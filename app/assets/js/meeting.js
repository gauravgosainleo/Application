(function () {
    'use strict';

    var cfg = window.KOENIG_CONFIG;
    var app = document.getElementById('meeting-app');
    var preview = document.getElementById('preview-video');
    var joinBtn = document.getElementById('join-btn');
    var nameInput = document.getElementById('display-name');
    var prejoin = document.getElementById('prejoin');
    var prejoinError = document.getElementById('prejoin-error');
    var room = document.getElementById('room');
    var grid = document.getElementById('video-grid');
    var peerCountEl = document.getElementById('peer-count');
    var toggleAudioBtn = document.getElementById('toggle-audio');
    var toggleVideoBtn = document.getElementById('toggle-video');
    var leaveBtn = document.getElementById('leave');
    var copyLinkBtn = document.getElementById('copy-link');

    var localStream = null;
    var peerId = null;
    var displayName = '';
    var peers = {};                 // peer_id -> { pc, stream, name, tile }
    var pendingCandidates = {};     // peer_id -> [candidate,...]
    var pollTimer = null;
    var peersTimer = null;
    var heartbeatTimer = null;
    var joined = false;

    var rtcConfig = {
        iceServers: [
            { urls: 'stun:stun.l.google.com:19302' },
            { urls: 'stun:stun1.l.google.com:19302' },
            { urls: 'stun:global.stun.twilio.com:3478' }
        ]
    };

    // ---------- prejoin ----------
    function startPreview() {
        return navigator.mediaDevices.getUserMedia({ video: true, audio: true })
            .then(function (stream) {
                localStream = stream;
                preview.srcObject = stream;
            });
    }

    function preError(msg) {
        prejoinError.textContent = msg;
        prejoinError.classList.remove('hidden');
    }

    startPreview().catch(function (err) {
        preError('Could not access camera/microphone: ' + err.message);
        joinBtn.disabled = true;
    });

    var savedName = '';
    try { savedName = localStorage.getItem('koenig:name') || ''; } catch (e) {}
    if (savedName) nameInput.value = savedName;

    joinBtn.addEventListener('click', function () {
        displayName = (nameInput.value || '').trim();
        if (!displayName) { preError('Please enter your name'); return; }
        try { localStorage.setItem('koenig:name', displayName); } catch (e) {}
        joinBtn.disabled = true;
        joinBtn.textContent = 'Joining...';
        joinMeeting();
    });

    // ---------- join flow ----------
    function joinMeeting() {
        fetch(cfg.apiBase + 'join.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: cfg.code, name: displayName })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
          .then(function (res) {
            if (!res.ok) throw new Error(res.body.error || 'Could not join');
            peerId = res.body.peer_id;
            joined = true;
            showRoom();
            startLoops();
          })
          .catch(function (err) {
            joinBtn.disabled = false;
            joinBtn.textContent = 'Join meeting';
            preError(err.message);
          });
    }

    function showRoom() {
        prejoin.classList.add('hidden');
        room.classList.remove('hidden');
        addSelfTile();
    }

    function addSelfTile() {
        var tile = tileElement('self', displayName + ' (you)');
        tile.querySelector('video').srcObject = localStream;
        tile.querySelector('video').muted = true;
        grid.appendChild(tile);
        updatePeerCount();
    }

    function tileElement(id, label) {
        var el = document.createElement('div');
        el.className = 'tile';
        el.dataset.tileId = id;
        el.innerHTML = '<video autoplay playsinline></video>' +
                       '<div class="tile-label"><span class="name"></span>' +
                       '<span class="state" hidden></span></div>';
        el.querySelector('.name').textContent = label;
        return el;
    }

    function updatePeerCount() {
        var n = 1 + Object.keys(peers).length;
        peerCountEl.textContent = n;
    }

    // ---------- polling loops ----------
    function startLoops() {
        pollTimer = setInterval(pollSignals, 1200);
        peersTimer = setInterval(refreshPeers, 3000);
        heartbeatTimer = setInterval(function () { /* signal poll already beats */ }, 8000);
        pollSignals();
        refreshPeers();
    }

    function stopLoops() {
        clearInterval(pollTimer); clearInterval(peersTimer); clearInterval(heartbeatTimer);
        pollTimer = peersTimer = heartbeatTimer = null;
    }

    // ---------- peer management ----------
    function refreshPeers() {
        if (!joined) return;
        fetch(cfg.apiBase + 'peers.php?code=' + encodeURIComponent(cfg.code) + '&peer=' + encodeURIComponent(peerId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.peers) return;
                var seen = {};
                data.peers.forEach(function (p) {
                    seen[p.peer_id] = true;
                    if (!peers[p.peer_id]) {
                        createPeer(p.peer_id, p.display_name, peerId < p.peer_id);
                    }
                });
                // Drop peers no longer present.
                Object.keys(peers).forEach(function (pid) {
                    if (!seen[pid]) removePeer(pid);
                });
                updatePeerCount();
            })
            .catch(function () { /* ignore transient network errors */ });
    }

    function createPeer(remoteId, remoteName, shouldOffer) {
        if (peers[remoteId]) return peers[remoteId];
        var pc = new RTCPeerConnection(rtcConfig);
        var tile = tileElement(remoteId, remoteName || 'Guest');
        grid.appendChild(tile);

        var entry = { pc: pc, tile: tile, name: remoteName, stream: null };
        peers[remoteId] = entry;

        localStream.getTracks().forEach(function (track) {
            pc.addTrack(track, localStream);
        });

        pc.onicecandidate = function (ev) {
            if (ev.candidate) {
                sendSignal(remoteId, 'ice', ev.candidate.toJSON ? ev.candidate.toJSON() : ev.candidate);
            }
        };
        pc.ontrack = function (ev) {
            var stream = ev.streams && ev.streams[0];
            if (!stream) return;
            entry.stream = stream;
            var v = tile.querySelector('video');
            if (v.srcObject !== stream) v.srcObject = stream;
        };
        pc.onconnectionstatechange = function () {
            if (pc.connectionState === 'failed' || pc.connectionState === 'closed') {
                // Let refreshPeers decide; keep tile until peer drops from DB.
            }
        };

        if (shouldOffer) {
            pc.createOffer().then(function (offer) {
                return pc.setLocalDescription(offer).then(function () {
                    sendSignal(remoteId, 'offer', { sdp: offer.sdp, type: offer.type });
                });
            }).catch(function (e) { console.warn('offer failed', e); });
        }

        updatePeerCount();
        return entry;
    }

    function removePeer(pid) {
        var p = peers[pid];
        if (!p) return;
        try { p.pc.close(); } catch (e) {}
        if (p.tile && p.tile.parentNode) p.tile.parentNode.removeChild(p.tile);
        delete peers[pid];
        updatePeerCount();
    }

    // ---------- signalling ----------
    function sendSignal(to, type, payload) {
        return fetch(cfg.apiBase + 'signal.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: cfg.code, from: peerId, to: to, type: type, payload: payload })
        }).catch(function () {});
    }

    function pollSignals() {
        if (!joined) return;
        fetch(cfg.apiBase + 'signal.php?code=' + encodeURIComponent(cfg.code) + '&peer=' + encodeURIComponent(peerId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.signals) return;
                data.signals.forEach(handleSignal);
            })
            .catch(function () {});
    }

    function handleSignal(sig) {
        var from = sig.from;
        var type = sig.type;
        var payload = sig.payload;
        var entry = peers[from];
        if (!entry && type === 'offer') {
            entry = createPeer(from, 'Guest', false);
        }
        if (!entry) return;
        var pc = entry.pc;

        if (type === 'offer') {
            var offerDesc = new RTCSessionDescription(payload);
            pc.setRemoteDescription(offerDesc)
                .then(function () { return drainCandidates(from); })
                .then(function () { return pc.createAnswer(); })
                .then(function (ans) { return pc.setLocalDescription(ans).then(function () { return ans; }); })
                .then(function (ans) { sendSignal(from, 'answer', { sdp: ans.sdp, type: ans.type }); })
                .catch(function (e) { console.warn('offer handling failed', e); });
        } else if (type === 'answer') {
            pc.setRemoteDescription(new RTCSessionDescription(payload))
                .then(function () { return drainCandidates(from); })
                .catch(function (e) { console.warn('answer failed', e); });
        } else if (type === 'ice') {
            if (!payload) return;
            if (!pc.remoteDescription || !pc.remoteDescription.type) {
                (pendingCandidates[from] = pendingCandidates[from] || []).push(payload);
            } else {
                pc.addIceCandidate(new RTCIceCandidate(payload)).catch(function () {});
            }
        } else if (type === 'bye') {
            removePeer(from);
        } else if (type === 'media-state') {
            updatePeerMediaLabel(from, payload);
        }
    }

    function drainCandidates(from) {
        var pc = peers[from] && peers[from].pc;
        var arr = pendingCandidates[from] || [];
        pendingCandidates[from] = [];
        if (!pc) return Promise.resolve();
        return Promise.all(arr.map(function (c) {
            return pc.addIceCandidate(new RTCIceCandidate(c)).catch(function () {});
        }));
    }

    function updatePeerMediaLabel(pid, state) {
        var p = peers[pid];
        if (!p) return;
        var s = p.tile.querySelector('.state');
        var parts = [];
        if (state && state.audio === false) parts.push('muted');
        if (state && state.video === false) parts.push('cam off');
        if (parts.length) { s.hidden = false; s.textContent = parts.join(' · '); }
        else { s.hidden = true; s.textContent = ''; }
    }

    // ---------- controls ----------
    var audioEnabled = true, videoEnabled = true;

    toggleAudioBtn.addEventListener('click', function () {
        audioEnabled = !audioEnabled;
        localStream.getAudioTracks().forEach(function (t) { t.enabled = audioEnabled; });
        toggleAudioBtn.classList.toggle('off', !audioEnabled);
        toggleAudioBtn.querySelector('.label').textContent = audioEnabled ? 'Mute' : 'Unmute';
        broadcastMediaState();
    });

    toggleVideoBtn.addEventListener('click', function () {
        videoEnabled = !videoEnabled;
        localStream.getVideoTracks().forEach(function (t) { t.enabled = videoEnabled; });
        toggleVideoBtn.classList.toggle('off', !videoEnabled);
        toggleVideoBtn.querySelector('.label').textContent = videoEnabled ? 'Camera off' : 'Camera on';
        broadcastMediaState();
    });

    function broadcastMediaState() {
        Object.keys(peers).forEach(function (pid) {
            sendSignal(pid, 'media-state', { audio: audioEnabled, video: videoEnabled });
        });
    }

    leaveBtn.addEventListener('click', leave);

    copyLinkBtn.addEventListener('click', function () {
        var tmp = document.createElement('input');
        tmp.value = cfg.joinUrl;
        document.body.appendChild(tmp);
        tmp.select();
        try { document.execCommand('copy'); copyLinkBtn.textContent = 'Copied!'; setTimeout(function () { copyLinkBtn.textContent = 'Copy invite link'; }, 1500); } catch (e) {}
        document.body.removeChild(tmp);
    });

    function leave() {
        if (!joined) { window.location.href = 'index.php'; return; }
        joined = false;
        // notify peers (best-effort)
        Object.keys(peers).forEach(function (pid) { sendSignal(pid, 'bye', null); });
        stopLoops();
        Object.keys(peers).forEach(removePeer);
        if (localStream) localStream.getTracks().forEach(function (t) { t.stop(); });
        // best-effort leave on server
        try {
            var blob = new Blob([JSON.stringify({ code: cfg.code, peer: peerId })], { type: 'application/json' });
            if (navigator.sendBeacon) navigator.sendBeacon(cfg.apiBase + 'leave.php', blob);
            else fetch(cfg.apiBase + 'leave.php', { method: 'POST', body: blob, headers: { 'Content-Type': 'application/json' }, keepalive: true });
        } catch (e) {}
        window.location.href = 'index.php';
    }

    window.addEventListener('beforeunload', function () {
        if (!joined) return;
        try {
            var blob = new Blob([JSON.stringify({ code: cfg.code, peer: peerId })], { type: 'application/json' });
            if (navigator.sendBeacon) navigator.sendBeacon(cfg.apiBase + 'leave.php', blob);
        } catch (e) {}
    });
})();
