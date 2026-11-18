/* Kiosk scanner: kamera (html5-qrcode) atau scanner USB (mengetik kode lalu Enter). */
(function () {
    var app = document.querySelector('[data-kiosk]');
    if (!app) return;
    var scanUrl = app.dataset.scanUrl, statsUrl = app.dataset.statsUrl;
    var $ = function (s) { return app.querySelector(s); };
    var result = $('[data-result]'), input = $('[data-code-input]');
    var last = { code: '', at: 0 }, busy = false, resetTimer = null, buffer = '', bufferAt = 0;

    // Jam
    function tick() {
        var d = new Date();
        $('[data-clock]').textContent = d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
        $('[data-date]').textContent = d.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }
    tick(); setInterval(tick, 1000);

    // Bunyi pendek: nada tinggi = berhasil, rendah = gagal
    var audio;
    function beep(ok) {
        try {
            audio = audio || new (window.AudioContext || window.webkitAudioContext)();
            var o = audio.createOscillator(), g = audio.createGain();
            o.frequency.value = ok ? 1046 : 220; o.type = ok ? 'sine' : 'square';
            g.gain.setValueAtTime(0.18, audio.currentTime);
            g.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + (ok ? 0.18 : 0.4));
            o.connect(g); g.connect(audio.destination); o.start(); o.stop(audio.currentTime + 0.4);
        } catch (e) {}
    }

    function show(r) {
        result.className = 'result result-' + (r.tone || 'error');
        $('[data-r-title]').textContent = r.title || '';
        $('[data-r-name]').textContent = r.student ? r.student.name + (r.student.class ? ' · ' + r.student.class : '') : '';
        $('[data-r-msg]').textContent = r.message || '';
        $('[data-r-avatar]').textContent = r.student ? r.student.initials : '!';
        if (r.stats) updateStats(r.stats);
        if (r.student) {
            var li = document.createElement('li');
            li.className = 'log-' + r.tone;
            li.innerHTML = '<span></span><b></b><time></time>';
            li.children[1].textContent = r.student.name;
            li.children[2].textContent = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
            var log = $('[data-log]');
            log.insertBefore(li, log.firstChild);
            while (log.children.length > 6) log.removeChild(log.lastChild);
        }
        clearTimeout(resetTimer);
        resetTimer = setTimeout(function () {
            result.className = 'result result-idle';
            $('[data-r-title]').textContent = 'Siap memindai';
            $('[data-r-name]').textContent = 'Silakan scan kartu QR';
            $('[data-r-msg]').textContent = '';
            $('[data-r-avatar]').textContent = '';
        }, 4000);
    }

    function updateStats(s) {
        $('[data-s-in]').textContent = s.in; $('[data-s-late]').textContent = s.late; $('[data-s-total]').textContent = s.total;
    }

    function send(code) {
        code = (code || '').trim();
        if (!code || busy) return;
        var now = Date.now();
        if (code === last.code && now - last.at < 3000) return; // kartu yang sama masih di depan kamera
        last = { code: code, at: now };
        busy = true;
        fetch(scanUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ code: code })
        }).then(function (r) {
            if (r.status === 404) throw new Error('Tautan kiosk tidak berlaku lagi. Minta tautan baru ke admin.');
            if (r.status === 429) throw new Error('Terlalu banyak scan, tunggu sebentar.');
            return r.json();
        }).then(function (r) {
            show(r); beep(r.ok && r.tone !== 'error');
        }).catch(function (e) {
            show({ tone: 'error', title: 'Tidak terhubung', message: e.message || 'Periksa koneksi internet.' }); beep(false);
        }).finally(function () { busy = false; });
    }

    // Scanner USB = keyboard: kumpulkan karakter yang datang cepat, kirim saat Enter.
    document.addEventListener('keydown', function (e) {
        if (e.target === input) return;
        var now = Date.now();
        if (now - bufferAt > 120) buffer = '';
        bufferAt = now;
        if (e.key === 'Enter') { if (buffer.length >= 6) send(buffer); buffer = ''; return; }
        if (e.key.length === 1) buffer += e.key;
    });

    $('[data-manual]').addEventListener('submit', function (e) {
        e.preventDefault(); send(input.value); input.value = '';
    });

    // Kamera
    $('[data-camera-start]').addEventListener('click', function () {
        if (!window.Html5Qrcode) return;
        var cam = new Html5Qrcode('reader');
        cam.start({ facingMode: 'environment' }, { fps: 12, qrbox: function (w, h) { var s = Math.floor(Math.min(w, h) * 0.7); return { width: s, height: s }; } },
            function (text) { send(text); }, function () {})
            .then(function () { $('[data-camera-idle]').hidden = true; app.classList.add('camera-on'); })
            .catch(function () { show({ tone: 'error', title: 'Kamera tidak bisa dibuka', message: 'Izinkan akses kamera, atau pakai scanner USB.' }); });
    });

    setInterval(function () {
        fetch(statsUrl, { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(updateStats).catch(function () {});
    }, 30000);
})();
