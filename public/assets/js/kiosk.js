/* Kiosk scanner (Fase 3): scanner USB (keyboard wedge) + kamera, satu request per scan, bunyi & warna, siap scan otomatis.
   Token perangkat disimpan di localStorage; server hanya menyimpan hash-nya. */
(function () {
  'use strict';
  var T = window.T || function (s, p) { for (var k in (p || {})) { s = s.split(':' + k).join(p[k]); } return s; };
  var $ = function (s) { return document.querySelector(s); };
  var root = $('#kiosk');
  if (!root) return;
  var URL_PAIR = root.getAttribute('data-pair'), URL_ME = root.getAttribute('data-me'), URL_SCAN = root.getAttribute('data-scan'), URL_PHOTO = root.getAttribute('data-photo');
  var USE_CAMERA = root.getAttribute('data-camera') === '1';
  var VERSION = root.getAttribute('data-version') || '3';
  var KEY = 'absensi.device.token';
  var token = null;
  try { token = localStorage.getItem(KEY); } catch (e) { token = null; }

  var el = {
    pair: $('#k-pair'), pairForm: $('#k-pair-form'), code: $('#k-code'), pairErr: $('#k-pair-err'),
    main: $('#k-main'), result: $('#k-result'), photo: $('#k-photo'), title: $('#k-title'), sub: $('#k-sub'), meta: $('#k-meta'),
    device: $('#k-device'), clock: $('#k-clock'), net: $('#k-net'), sessionT: $('#k-session-t'), sessionS: $('#k-session-s'),
    count: $('#k-count'), countH: $('#k-count-h'), countT: $('#k-count-t'), input: $('#k-input'), manual: $('#k-manual'),
    stats: $('#k-stats'), sound: $('#k-sound'), full: $('#k-full'), unpair: $('#k-unpair'),
    cam: $('#k-cam'), camMsg: $('#k-cam-msg'), camSwitch: $('#k-cam-switch'), camRetry: $('#k-cam-retry')
  };
  var icons = {};
  var tpl = document.getElementById('k-icons');
  Array.prototype.forEach.call((tpl && tpl.content ? tpl.content : tpl).querySelectorAll('[data-i]'), function (s) { icons[s.getAttribute('data-i')] = s.innerHTML; });

  /* ---------- jam ---------- */
  function tickClock() { var d = new Date(); el.clock.textContent = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ':' + ('0' + d.getSeconds()).slice(-2); }
  tickClock(); setInterval(tickClock, 1000);

  /* ---------- suara (WebAudio, tanpa file) ---------- */
  var actx = null;
  function audio() {
    if (!actx) { try { actx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { actx = null; } }
    if (actx && actx.state === 'suspended') { actx.resume(); }
    el.sound.hidden = !(actx && actx.state !== 'running');
    return actx;
  }
  function tone(freq, ms, type, delay, gain) {
    var a = audio(); if (!a || a.state !== 'running') return;
    var o = a.createOscillator(), g = a.createGain();
    o.type = type || 'sine'; o.frequency.value = freq;
    g.gain.value = gain || 0.18;
    o.connect(g); g.connect(a.destination);
    var t = a.currentTime + (delay || 0) / 1000;
    o.start(t); g.gain.setValueAtTime(gain || 0.18, t); g.gain.exponentialRampToValueAtTime(0.0001, t + ms / 1000); o.stop(t + ms / 1000 + 0.02);
  }
  var SOUND = {
    ok: function () { tone(1047, 140); },
    late: function () { tone(880, 120); tone(880, 120, 'sine', 170); },
    dup: function () { tone(660, 160); },
    bad: function () { tone(220, 320, 'square', 0, 0.12); },
    mute: function () { tone(330, 200, 'triangle'); }
  };
  el.sound.addEventListener('click', function () { audio(); SOUND.ok(); });
  document.addEventListener('pointerdown', function () { audio(); }, { once: true });

  /* ---------- jaringan & api ---------- */
  function net(on, text) { el.net.className = 'k-net ' + (on ? 'on' : 'off'); el.net.querySelector('span').textContent = text || (on ? T('Terhubung') : T('Terputus')); }
  function api(url, body, timeoutMs) {
    var ctl = ('AbortController' in window) ? new AbortController() : null;
    var timer = ctl ? setTimeout(function () { ctl.abort(); }, timeoutMs || 6000) : null;
    var headers = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
    if (token) headers['X-Device-Token'] = token;
    return fetch(url, { method: body ? 'POST' : 'GET', headers: headers, body: body ? JSON.stringify(body) : undefined, credentials: 'same-origin', cache: 'no-store', signal: ctl ? ctl.signal : undefined })
      .then(function (r) { return r.json().then(function (d) { d._http = r.status; return d; }); })
      .finally(function () { if (timer) clearTimeout(timer); });
  }

  /* ---------- pairing ---------- */
  function showPair(err) {
    el.pair.hidden = false; el.main.hidden = true; el.unpair.hidden = true;
    el.pairErr.hidden = !err; el.pairErr.textContent = err || '';
    el.device.textContent = T('Scanner belum dipasangkan');
    setTimeout(function () { el.code.focus(); }, 50);
  }
  el.pairForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var code = (el.code.value || '').replace(/\D/g, '');
    if (code.length !== 6) { el.pairErr.hidden = false; el.pairErr.textContent = T('Masukkan 6 digit kode.'); return; }
    var btn = el.pairForm.querySelector('button'); btn.disabled = true;
    api(URL_PAIR, { code: code, app_version: VERSION }).then(function (d) {
      if (d.ok && d.token) {
        token = d.token;
        try { localStorage.setItem(KEY, token); } catch (err) { /* mode privat: token hanya di memori */ }
        el.code.value = '';
        start();
      } else { showPair(d.message || T('Kode pasangan salah.')); }
    }).catch(function () { showPair(T('Tidak bisa menghubungi server. Periksa jaringan.')); })
      .finally(function () { btn.disabled = false; });
  });
  el.unpair.addEventListener('click', function () {
    if (!confirm(T('Lepas perangkat ini? Scanner berhenti sampai dipasangkan ulang dengan kode baru.'))) return;
    try { localStorage.removeItem(KEY); } catch (e) { /* abaikan */ }
    token = null; stopCamera(); showPair();
  });
  el.full.addEventListener('click', function () {
    var d = document.documentElement;
    if (document.fullscreenElement) { document.exitFullscreen(); } else if (d.requestFullscreen) { d.requestFullscreen(); }
  });

  /* ---------- sesi & identitas ---------- */
  var sessions = [];
  var counts = {};
  function renderSession() {
    var s = sessions[0];
    if (!s) {
      el.sessionT.textContent = T('Belum ada jadwal di ruangan ini');
      el.sessionS.textContent = T('Sesi dibuat otomatis mengikuti jadwal pelajaran ruangan ini.');
      el.count.hidden = true; return;
    }
    el.sessionT.textContent = s.label + (sessions.length > 1 ? ' +' + (sessions.length - 1) : '');
    el.sessionS.textContent = s.starts_at + '–' + s.ends_at + ' · ' + T('Terlambat setelah :t', { t: s.late_after });
    var c = counts[s.id] || { h: 0, t: 0 };
    el.countH.textContent = c.h; el.countT.textContent = c.t; el.count.hidden = false;
  }
  var meTimer = null;
  function refreshMe() {
    if (!token) return Promise.resolve();
    return api(URL_ME).then(function (d) {
      if (d._http === 401) { try { localStorage.removeItem(KEY); } catch (e) { /* */ } token = null; stopCamera(); showPair(d.message); return; }
      if (d._http === 403) { stopCamera(); showPair(d.message); return; }
      if (!d.ok) { net(false, d.message); return; }
      net(true);
      el.device.textContent = d.device.name + (d.device.room ? ' · ' + d.device.room : ' · ' + T('belum ada ruangan'));
      sessions = d.sessions || [];
      renderSession();
    }).catch(function () { net(false); });
  }
  function start() {
    el.pair.hidden = true; el.main.hidden = false; el.unpair.hidden = false;
    ready();
    refreshMe().then(function () { if (token && USE_CAMERA) startCamera(); });
    clearInterval(meTimer); meTimer = setInterval(refreshMe, 30000);
    setTimeout(function () { el.input.focus(); }, 80);
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden && token) { refreshMe(); if (USE_CAMERA) startCamera(); } });

  /* ---------- tampilan hasil ---------- */
  var readyTimer = null;
  function setPhoto(student) {
    el.photo.innerHTML = '';
    if (student && student.photo) {
      var img = document.createElement('img'); img.alt = ''; img.src = URL_PHOTO + '/' + student.id + '?kecil=0';
      img.onerror = function () { setInitials(student.name); };
      el.photo.appendChild(img); return;
    }
    if (student && student.name) { setInitials(student.name); return; }
    el.photo.innerHTML = '<span class="k-ic">' + (icons.scan || '') + '</span>';
  }
  function setInitials(name) {
    var p = (name || '').trim().split(/\s+/).slice(0, 2).map(function (x) { return x.charAt(0).toUpperCase(); }).join('');
    el.photo.innerHTML = '<span class="k-initials">' + (p || '?') + '</span>';
  }
  function setIcon(name) { el.photo.innerHTML = '<span class="k-ic">' + (icons[name] || '') + '</span>'; }
  function flash(kind) { root.setAttribute('data-flash', kind); setTimeout(function () { if (root.getAttribute('data-flash') === kind) root.removeAttribute('data-flash'); }, 700); }
  function ready() {
    clearTimeout(readyTimer);
    el.result.setAttribute('data-state', 'ready');
    setIcon('scan');
    el.title.textContent = T('Siap memindai');
    el.sub.textContent = T('Tempelkan kartu QR siswa ke scanner');
    el.meta.textContent = sessions[0] ? sessions[0].label : '';
  }
  function show(state, title, sub, meta, student, ms) {
    clearTimeout(readyTimer);
    el.result.setAttribute('data-state', state);
    if (student) setPhoto(student); else setIcon({ ok: 'check', late: 'clock', dup: 'info', wrongclass: 'x', invalid: 'x', revoked: 'lock', nosession: 'alert', offline: 'wifioff', error: 'alert', busy: 'scan' }[state] || 'scan');
    el.title.textContent = title; el.sub.textContent = sub || ''; el.meta.textContent = meta || '';
    readyTimer = setTimeout(ready, ms || 2500);
  }

  /* ---------- statistik kecepatan (klien) ---------- */
  var samples = [];
  function stat(tTotal, tRound, tServer) {
    samples.push({ t: tTotal, r: tRound, s: tServer || 0 }); if (samples.length > 100) samples.shift();
    var ts = samples.map(function (x) { return x.t; }).sort(function (a, b) { return a - b; });
    var avg = ts.reduce(function (a, b) { return a + b; }, 0) / ts.length, p95 = ts[Math.max(0, Math.ceil(ts.length * 0.95) - 1)];
    var sAvg = samples.reduce(function (a, b) { return a + b.s; }, 0) / samples.length;
    el.stats.textContent = T(':n scan · total rata-rata :a ms · p95 :p ms · server :s ms', { n: samples.length, a: Math.round(avg), p: Math.round(p95), s: sAvg.toFixed(1) });
  }

  /* ---------- scan ---------- */
  var last = { code: '', at: 0 };
  var inflight = false, queued = null;
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var a = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  function localIso(d) {
    var p = function (n) { return ('0' + n).slice(-2); };
    var off = -d.getTimezoneOffset(), sign = off >= 0 ? '+' : '-'; off = Math.abs(off);
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()) + sign + p(Math.floor(off / 60)) + ':' + p(off % 60);
  }
  function handle(raw, source) {
    var code = String(raw || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (code.length < 6) return;
    var now = Date.now();
    if (code === last.code && now - last.at < 3000) return; // scanner USB/kamera sering membaca dua kali
    last = { code: code, at: now };
    if (inflight) { queued = { code: code, t0: performance.now() }; return; }
    send(code, performance.now(), source);
  }
  function send(code, t0, source) {
    inflight = true;
    show('busy', T('Memeriksa…'), '', '', null, 8000);
    var scanId = uuid();
    var tSend = performance.now();
    api(URL_SCAN, { scan_id: scanId, code: code, scanned_at: localIso(new Date()), metrics: { source: source || 'usb', t_decode_ms: Math.round(tSend - t0) } }, 8000)
      .then(function (d) {
        var tRound = performance.now() - tSend;
        render(d);
        var tTotal = performance.now() - t0;
        stat(tTotal, tRound, d.t_server_ms);
        net(true);
      })
      .catch(function () {
        net(false);
        SOUND.bad(); flash('bad');
        show('offline', T('Tidak ada koneksi'), T('Scan belum tersimpan. Coba lagi.'), '', null, 4000);
      })
      .finally(function () {
        inflight = false;
        if (queued) { var q = queued; queued = null; send(q.code, q.t0, source); }
      });
  }
  function render(d) {
    if (d._http === 401) { try { localStorage.removeItem(KEY); } catch (e) { /* */ } token = null; stopCamera(); showPair(d.message); return; }
    if (d._http === 403) { SOUND.bad(); flash('bad'); show('revoked', T('Perangkat dicabut'), d.message || '', '', null, 6000); return; }
    var st = d.student || null;
    var cls = st && st.class ? st.class : '';
    var s = d.session || null;
    switch (d.state) {
      case 'ok':
        SOUND.ok(); flash('ok'); bump(s, 'h');
        show('ok', st.name, d.message || T('Hadir'), d.scanned_at + (s ? ' · ' + s.label : ' · ' + cls), st, 2200); break;
      case 'late':
        SOUND.late(); flash('late'); bump(s, 't');
        show('late', st.name, d.message, d.scanned_at + (s ? ' · ' + s.label : ' · ' + cls), st, 2800); break;
      case 'dup':
        SOUND.dup(); flash('dup');
        show('dup', st.name, d.message, s ? s.label : cls, st, 2500); break;
      case 'wrongclass':
        SOUND.bad(); flash('bad');
        show('wrongclass', st ? st.name : T('Salah kelas'), d.message, cls ? T('Kelas siswa: :c', { c: cls }) : '', st, 4000); break;
      case 'nosession':
        SOUND.mute(); flash('mute');
        show('nosession', T('Belum ada jadwal di ruangan ini'), st ? st.name + (cls ? ' · ' + cls : '') : '', T('Kehadiran tidak tersimpan'), null, 4000); break;
      case 'invalid':
        SOUND.bad(); flash('bad');
        show('invalid', T('Kartu tidak berlaku'), T('Hubungi TU'), '', null, 4000); break;
      default:
        SOUND.bad(); flash('bad');
        show('error', T('Gagal'), d.message || T('Terjadi kesalahan di server.'), '', null, 4000);
    }
  }
  function bump(s, k) {
    if (!s) return;
    counts[s.id] = counts[s.id] || { h: 0, t: 0 };
    counts[s.id][k]++;
    if (k === 't') counts[s.id].h++; // terlambat termasuk hadir
    renderSession();
  }

  /* ---------- scanner USB (keyboard wedge): ketikan cepat + Enter, di mana pun fokusnya ---------- */
  var buf = '', bufAt = 0;
  document.addEventListener('keydown', function (e) {
    if (el.pair && !el.pair.hidden) return;
    if (e.target === el.code) return;
    var now = Date.now();
    if (now - bufAt > 400) buf = '';
    bufAt = now;
    if (e.key === 'Enter') {
      if (buf.length >= 6) { e.preventDefault(); var c = buf; buf = ''; if (e.target === el.input) el.input.value = ''; handle(c, 'usb'); }
      return;
    }
    if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) { buf += e.key; }
  });
  el.manual.addEventListener('submit', function (e) {
    e.preventDefault();
    var v = el.input.value; el.input.value = ''; buf = '';
    handle(v, 'manual');
  });
  // Jaga fokus di kolom input agar scanner USB selalu masuk (tanpa mengganggu tombol)
  setInterval(function () {
    if (!token || el.main.hidden || document.hidden) return;
    var a = document.activeElement;
    if (!a || a === document.body) el.input.focus({ preventScroll: true });
  }, 1500);

  /* ---------- kamera (html5-qrcode) ---------- */
  var scanner = null, cams = [], camIdx = 0, camRunning = false;
  function camMsg(html) { if (el.camMsg) { el.camMsg.hidden = !html; el.camMsg.innerHTML = html || ''; } }
  function startCamera() {
    if (!USE_CAMERA || !window.Html5Qrcode || camRunning || !el.cam) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { camMsg(icons.alert + '<span>' + T('Kamera butuh HTTPS atau localhost.') + '</span>'); return; }
    camMsg('<span class="spin"></span>' + T('Menyalakan kamera…'));
    scanner = scanner || new Html5Qrcode('reader', { verbose: false });
    var cfg = { fps: 12, qrbox: function (w, h) { var s = Math.floor(Math.min(w, h) * 0.7); return { width: s, height: s }; }, aspectRatio: 1.333 };
    var onScan = function (text) { handle(text, 'camera'); };
    var pick = cams.length ? { deviceId: { exact: cams[camIdx].id } } : { facingMode: 'environment' };
    scanner.start(pick, cfg, onScan, function () { /* frame tanpa QR */ })
      .then(function () { camRunning = true; camMsg(''); el.camRetry.hidden = true; return Html5Qrcode.getCameras(); })
      .then(function (list) { cams = list || []; el.camSwitch.hidden = cams.length < 2; })
      .catch(function (err) {
        camRunning = false;
        var denied = /NotAllowed|Permission/i.test(String(err));
        camMsg(icons.alert + '<span>' + (denied ? T('Akses kamera ditolak. Izinkan kamera di pengaturan browser.') : T('Kamera tidak tersedia. Scanner USB tetap bisa dipakai.')) + '</span>');
        el.camRetry.hidden = false;
      });
  }
  function stopCamera() {
    if (scanner && camRunning) { camRunning = false; scanner.stop().catch(function () { /* */ }); }
  }
  if (el.camSwitch) el.camSwitch.addEventListener('click', function () {
    if (cams.length < 2) return;
    camIdx = (camIdx + 1) % cams.length;
    var p = scanner && camRunning ? scanner.stop() : Promise.resolve();
    camRunning = false;
    p.then(startCamera).catch(startCamera);
  });
  if (el.camRetry) el.camRetry.addEventListener('click', startCamera);

  /* ---------- mulai ---------- */
  if (token) { start(); } else { showPair(); }
})();
