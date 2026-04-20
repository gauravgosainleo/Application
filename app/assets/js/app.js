(function () {
    var form = document.getElementById('quick-meeting-form');
    var errorEl = document.getElementById('form-error');
    var resultEl = document.getElementById('link-result');
    var linkInput = document.getElementById('m-link');
    var joinBtn = document.getElementById('m-join');
    var copyBtn = document.getElementById('m-copy');
    var titleEl = resultEl.querySelector('.meeting-title');
    var submitBtn = document.getElementById('m-generate');

    function showError(msg) {
        errorEl.textContent = msg;
        errorEl.classList.remove('hidden');
    }
    function clearError() {
        errorEl.textContent = '';
        errorEl.classList.add('hidden');
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearError();
        var name = document.getElementById('m-name').value.trim();
        var desc = document.getElementById('m-desc').value.trim();
        if (!name) { showError('Please enter a meeting name'); return; }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Generating...';

        fetch('api/create_meeting.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: name, description: desc })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
          .then(function (res) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Generate link';
            if (!res.ok) { showError(res.body.error || 'Could not create meeting'); return; }
            titleEl.textContent = res.body.name;
            linkInput.value = res.body.join_url;
            joinBtn.href = res.body.join_url;
            resultEl.classList.remove('hidden');
            resultEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          })
          .catch(function (err) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Generate link';
            showError('Network error: ' + err.message);
          });
    });

    copyBtn.addEventListener('click', function () {
        linkInput.select();
        linkInput.setSelectionRange(0, 9999);
        try {
            document.execCommand('copy');
            copyBtn.textContent = 'Copied!';
            setTimeout(function () { copyBtn.textContent = 'Copy'; }, 1500);
        } catch (e) {}
    });
})();
