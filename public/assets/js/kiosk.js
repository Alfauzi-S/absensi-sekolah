/* Kiosk scanner (Fase 4): scanner USB (keyboard wedge) + kamera.
   - Roster lokal: tanda Hadir/Terlambat tampil seketika dari daftar kartu yang diunduh, tanpa menunggu server.
   - Antrean offline (IndexedDB): setiap scan punya scan_id unik, dikirim ulang otomatis (satuan saat online, batch saat menumpuk).
   - Metrik: t_decode, t_feedback, t_roundtrip, t_total per scan, menumpang request berikutnya.
   Token perangkat disimpan di localStorage; server hanya menyimpan hash-nya. Server tetap sumber kebenaran. */
(function () {
  'use strict';
  var T = window.T || function (s, p) { for (var k in (p || {})) { s = s.split(':' + k).join(p[k]); } return s; };
  var $ = function (s) { return document.querySelector(s); };
  var root = $('#kiosk');
  if (!root) return;
  var A = function (n) { return root.getAttribute('data-' + n); };
  var URL_PAIR = A('pair'), URL_ME = A('me'), URL_SCAN = A('scan'), URL_BATCH = A('batch'), URL_ROSTER = A('roster'), URL_PHOTO = A('photo');
  var USE_CAMERA = A('camera') === '1', VERSION = A('version') || '4';
  var KEY = 'absensi.device.token', KEY_ROSTER = 'absensi.roster', KEY_DEVICE = 'absensi.device.label';
  var BATCH_SIZE = 50;
  var ls = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) { /* penuh / mode privat */ } },
    del: function (k) { try { localStorage.removeItem(k); } catch (e) { /* */ } }
  };
  var token = ls.get(KEY);

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

  /* ---------- jam: jam server = jam perangkat + selisih (diperbarui tiap /me dan roster) ---------- */
  var clockOffset = 0;
  function serverNow() { return Date.now() + clockOffset; }
  function syncClock(serverMs, tSent) { if (serverMs) clockOffset = Math.round(serverMs - (tSent + Date.now()) / 2); }
  function p2(n) { return ('0' + n).slice(-2); }
  function tickClock() { var d = new Date(); el.clock.textContent = p2(d.getHours()) + ':' + p2(d.getMinutes()) + ':' + p2(d.getSeconds()); }
  tickClock(); setInterval(tickClock, 1000);
  function localIso(d) {
    var off = -d.getTimezoneOffset(), sign = off >= 0 ? '+' : '-'; off = Math.abs(off);
    return d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate()) + 'T' + p2(d.getHours()) + ':' + p2(d.getMinutes()) + ':' + p2(d.getSeconds()) + sign + p2(Math.floor(off / 60)) + ':' + p2(off % 60);
  }

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
  var online = true;
  function net(on, text) {
    online = on;
    var q = pending.length;
    el.net.className = 'k-net ' + (on ? 'on' : 'off');
    el.net.querySelector('span').textContent = text || (on ? (q > 1 ? T('Menyinkronkan :n scan', { n: q }) : T('Terhubung')) : (q ? T('Offline · :n scan menunggu', { n: q }) : T('Offline')));
  }
  function api(url, body, timeoutMs) {
    var ctl = ('AbortController' in window) ? new AbortController() : null;
    var timer = ctl ? setTimeout(function () { ctl.abort(); }, timeoutMs || 6000) : null;
    var headers = { 'Accept': 'application/json' };
    if (body) headers['Content-Type'] = 'application/json';
    if (token) headers['X-Device-Token'] = token;
    return fetch(url, { method: body ? 'POST' : 'GET', headers: headers, body: body ? JSON.stringify(body) : undefined, credentials: 'same-origin', cache: 'no-store', signal: ctl ? ctl.signal : undefined })
      .then(function (r) { return r.json().then(function (d) { d._http = r.status; return d; }); })
      .finally(function () { if (timer) clearTimeout(timer); });
  }

  /* ---------- antrean offline: IndexedDB (cadangan: localStorage) ---------- */
  var pending = [];     // scan yang belum dipastikan server, urut waktu
  var store = (function () {
    var db = null, LSK = 'absensi.queue';
    function open() {
      return new Promise(function (resolve) {
        if (!('indexedDB' in window)) { resolve(null); return; }
        var req;
        try { req = indexedDB.open('absensi-kiosk', 1); } catch (e) { resolve(null); return; }
        req.onupgradeneeded = function () { req.result.createObjectStore('queue', { keyPath: 'scan_id' }); };
        req.onsuccess = function () { db = req.result; resolve(db); };
        req.onerror = function () { resolve(null); };
      });
    }
    function tx(mode, fn) {
      return new Promise(function (resolve) {
        if (!db) { resolve(null); return; }
        try {
          var t = db.transaction('queue', mode), out = fn(t.objectStore('queue'));
          t.oncomplete = function () { resolve(out && out.result !== undefined ? out.result : null); };
          t.onerror = t.onabort = function () { resolve(null); };
        } catch (e) { resolve(null); }
      });
    }
    function lsSave() { if (!db) ls.set(LSK, JSON.stringify(pending.slice(0, 2000))); }
    return {
      load: function () {
        return open().then(function (d) {
          if (!d) { try { return JSON.parse(ls.get(LSK) || '[]'); } catch (e) { return []; } }
          return tx('readonly', function (s) { return s.getAll(); }).then(function (rows) { return (rows || []).sort(function (a, b) { return a.at - b.at; }); });
        });
      },
      put: function (item) { if (!db) { lsSave(); return Promise.resolve(); } return tx('readwrite', function (s) { s.put(item); }); },
      del: function (ids) { if (!db) { lsSave(); return Promise.resolve(); } return tx('readwrite', function (s) { ids.forEach(function (id) { s.delete(id); }); }); }
    };
  })();

  /* ---------- roster lokal ---------- */
  var roster = { version: '', date: '', sessions: [], students: [], classes: {} };
  var byCode = {};      // kode → [kode, id, nama, kelas, foto]
  var present = {};     // kunci sesi → { idSiswa: true }
  var counts = {};      // kunci sesi → { h, t } selama kiosk ini menyala
  function applyRoster(r, persist) {
    roster = r; byCode = {};
    (r.students || []).forEach(function (s) { byCode[s[0]] = s; });
    (r.sessions || []).forEach(function (s) {
      present[s.key] = present[s.key] || {};
      (s.present || []).forEach(function (id) { present[s.key][id] = true; });
    });
    if (persist) ls.set(KEY_ROSTER, JSON.stringify(r));
    renderSession();
  }
  function preloadPhotos() {
    (roster.students || []).forEach(function (s) { if (s[4]) { var i = new Image(); i.src = URL_PHOTO + '/' + s[1] + '?kecil=1'; } });
  }
  function refreshRoster() {
    if (!token) return Promise.resolve();
    var t0 = Date.now();
    return api(URL_ROSTER + (roster.version ? '?v=' + encodeURIComponent(roster.version) : ''), null, 10000).then(function (d) {
      if (!d.ok) return;
      syncClock(d.server_ms, t0);
      if (d.unchanged) return;
      applyRoster({ version: d.version, date: d.date, sessions: d.sessions || [], students: d.students || [], classes: d.classes || {} }, true);
      preloadPhotos();
    }).catch(function () { /* offline: pakai roster tersimpan */ });
  }
  /** Sesi yang berlaku pada waktu t (ms jam server); aturan sama dengan server: jam berjalan mendahului jam berikutnya. */
  function activeSessions(t) {
    var live = (roster.sessions || []).filter(function (s) { return s.status === 'open' && s.from_ms <= t && t < s.ends_ms; });
    var running = live.filter(function (s) { return s.kind === 'jadwal' && s.starts_ms <= t; });
    var firstUpcoming = null;
    return live.filter(function (s) {
      if (s.kind !== 'jadwal' || s.starts_ms <= t) return true;
      if (running.length) return false;
      if (firstUpcoming === null) { firstUpcoming = s.key; return true; }
      return false;
    });
  }
  /** Keputusan lokal dari roster; null bila kartu tidak ada di roster (tunggu server). */
  function verdict(code, t) {
    var st = byCode[code];
    if (!st) return null;
    var student = { id: st[1], name: st[2], class: roster.classes[st[3]] || '', photo: !!st[4] };
    var act = activeSessions(t);
    if (!act.length) return { state: 'nosession', student: student };
    var match = act.filter(function (s) { return s.class_id === st[3]; });
    if (!match.length) return { state: 'wrongclass', student: student, session: act[0], message: T('Bukan kelas :c', { c: act[0].class }) };
    var s = match[0];
    if (present[s.key] && present[s.key][st[1]]) return { state: 'dup', student: student, session: s, message: T('Sudah tercatat') };
    match.forEach(function (m) { present[m.key] = present[m.key] || {}; present[m.key][st[1]] = true; });
    var d = new Date(Date.now()), hhmm = p2(d.getHours()) + ':' + p2(d.getMinutes());
    if (s.kind === 'harian_pulang') return { state: 'ok', student: student, session: s, message: T('Pulang tercatat'), scanned_at: hhmm };
    var late = Math.floor((t - s.starts_ms) / 60000), tol = Math.floor((s.late_ms - s.starts_ms) / 60000);
    if (late > tol) return { state: 'late', student: student, session: s, message: T('Terlambat :n menit', { n: late }), scanned_at: hhmm, late_minutes: late };
    return { state: 'ok', student: student, session: s, message: T('Hadir'), scanned_at: hhmm };
  }

  /* ---------- pairing ---------- */
  function showPair(err) {
    el.pair.hidden = false; el.main.hidden = true; el.unpair.hidden = true;
    el.pairErr.hidden = !err; el.pairErr.textContent = err || '';
    el.device.textContent = T('Scanner belum dipasangkan');
    setTimeout(function () { el.code.focus(); }, 50);
  }
  function unpaired(msg) { ls.del(KEY); token = null; stopCamera(); showPair(msg); }
  el.pairForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var code = (el.code.value || '').replace(/\D/g, '');
    if (code.length !== 6) { el.pairErr.hidden = false; el.pairErr.textContent = T('Masukkan 6 digit kode.'); return; }
    var btn = el.pairForm.querySelector('button'); btn.disabled = true;
    api(URL_PAIR, { code: code, app_version: VERSION }).then(function (d) {
      if (d.ok && d.token) {
        token = d.token; ls.set(KEY, token); ls.del(KEY_ROSTER);
        roster = { version: '', date: '', sessions: [], students: [], classes: {} }; byCode = {}; present = {};
        el.code.value = '';
        start();
      } else { showPair(d.message || T('Kode pasangan salah.')); }
    }).catch(function () { showPair(T('Tidak bisa menghubungi server. Periksa jaringan.')); })
      .finally(function () { btn.disabled = false; });
  });
  el.unpair.addEventListener('click', function () {
    var q = pending.length;
    if (!confirm(q ? T('Masih ada :n scan yang belum terkirim dan akan hilang. Lepas perangkat ini?', { n: q }) : T('Lepas perangkat ini? Scanner berhenti sampai dipasangkan ulang dengan kode baru.'))) return;
    store.del(pending.map(function (x) { return x.scan_id; })); pending = [];
    ls.del(KEY_ROSTER); unpaired();
  });
  el.full.addEventListener('click', function () {
    var d = document.documentElement;
    if (document.fullscreenElement) { document.exitFullscreen(); } else if (d.requestFullscreen) { d.requestFullscreen(); }
  });

  /* ---------- sesi & identitas ---------- */
  var meSessions = [];
  function currentSessions() {
    var act = activeSessions(serverNow());
    if (act.length) return act.map(function (s) { return { key: s.key, label: s.label, starts_at: s.starts.slice(11, 16), ends_at: s.ends.slice(11, 16), late_after: s.late.slice(11, 16) }; });
    return roster.version ? [] : meSessions;
  }
  function renderSession() {
    var list = currentSessions(), s = list[0];
    if (!s) {
      el.sessionT.textContent = T('Belum ada jadwal di ruangan ini');
      el.sessionS.textContent = T('Sesi dibuat otomatis mengikuti jadwal pelajaran ruangan ini.');
      el.count.hidden = true; return;
    }
    el.sessionT.textContent = s.label + (list.length > 1 ? ' +' + (list.length - 1) : '');
    el.sessionS.textContent = s.starts_at + '–' + s.ends_at + ' · ' + T('Terlambat setelah :t', { t: s.late_after });
    var c = counts[s.key] || { h: 0, t: 0 };
    el.countH.textContent = c.h; el.countT.textContent = c.t; el.count.hidden = false;
  }
  function bump(session, state) {
    if (!session || !session.key) return;
    var c = counts[session.key] = counts[session.key] || { h: 0, t: 0 };
    c.h++; if (state === 'late') c.t++;
    renderSession();
  }
  var meTimer = null, rosterTimer = null;
  function refreshMe() {
    if (!token) return Promise.resolve();
    var t0 = Date.now();
    return api(URL_ME + '?q=' + pending.length + '&t=' + encodeURIComponent(localIso(new Date()))).then(function (d) {
      if (d._http === 401) { unpaired(d.message); return; }
      if (d._http === 403) { stopCamera(); showPair(d.message); return; }
      if (!d.ok) { net(false, d.message); return; }
      syncClock(d.server_ms, t0);
      var label = d.device.name + (d.device.room ? ' · ' + d.device.room : ' · ' + T('belum ada ruangan'));
      el.device.textContent = label; ls.set(KEY_DEVICE, label);
      meSessions = (d.sessions || []).map(function (s) { return { key: 'id:' + s.id, label: s.label, starts_at: s.starts_at, ends_at: s.ends_at, late_after: s.late_after }; });
      net(true); renderSession(); flush();
    }).catch(function () { net(false); });
  }
  function start() {
    el.pair.hidden = true; el.main.hidden = false; el.unpair.hidden = false;
    el.device.textContent = ls.get(KEY_DEVICE) || '';
    try { var saved = JSON.parse(ls.get(KEY_ROSTER) || 'null'); if (saved && saved.version) applyRoster(saved, false); } catch (e) { /* roster rusak: unduh ulang */ }
    ready();
    store.load().then(function (rows) {
      var have = {}; pending.forEach(function (x) { have[x.scan_id] = 1; });
      pending = (rows || []).filter(function (x) { return !have[x.scan_id]; }).concat(pending);
      updateStats();
      return refreshMe();
    }).then(refreshRoster).then(function () { if (token && USE_CAMERA) startCamera(); });
    clearInterval(meTimer); meTimer = setInterval(refreshMe, 30000);
    clearInterval(rosterTimer); rosterTimer = setInterval(refreshRoster, 60000);
    setInterval(renderSession, 15000);
    setTimeout(function () { el.input.focus(); }, 80);
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden && token) { refreshMe(); refreshRoster(); if (USE_CAMERA) startCamera(); } });
  window.addEventListener('online', function () { if (token) { refreshMe(); } });
  window.addEventListener('offline', function () { net(false); });

  /* ---------- tampilan hasil ---------- */
  var readyTimer = null, shown = null; // shown = scan_id yang sedang tampil
  function setInitials(name) {
    var p = (name || '').trim().split(/\s+/).slice(0, 2).map(function (x) { return x.charAt(0).toUpperCase(); }).join('');
    el.photo.innerHTML = '<span class="k-initials">' + (p || '?') + '</span>';
  }
  function setPhoto(student) {
    if (student && student.photo) {
      var img = document.createElement('img'); img.alt = '';
      var triedThumb = !online;
      img.src = URL_PHOTO + '/' + student.id + (triedThumb ? '?kecil=1' : '');
      img.onerror = function () { if (!triedThumb) { triedThumb = true; img.src = URL_PHOTO + '/' + student.id + '?kecil=1'; } else { setInitials(student.name); } };
      el.photo.innerHTML = ''; el.photo.appendChild(img); return;
    }
    if (student && student.name) { setInitials(student.name); return; }
    setIcon('scan');
  }
  function setIcon(name) { el.photo.innerHTML = '<span class="k-ic">' + (icons[name] || '') + '</span>'; }
  function flash(kind) { root.setAttribute('data-flash', kind); setTimeout(function () { if (root.getAttribute('data-flash') === kind) root.removeAttribute('data-flash'); }, 700); }
  function ready() {
    clearTimeout(readyTimer); shown = null;
    el.result.setAttribute('data-state', 'ready');
    setIcon('scan');
    el.title.textContent = T('Siap memindai');
    el.sub.textContent = T('Tempelkan kartu QR siswa ke scanner');
    var s = currentSessions()[0];
    el.meta.textContent = s ? s.label : '';
  }
  function show(state, title, sub, meta, student, ms) {
    clearTimeout(readyTimer);
    el.result.setAttribute('data-state', state);
    if (student) setPhoto(student); else setIcon({ ok: 'check', late: 'clock', dup: 'info', wrongclass: 'x', invalid: 'x', revoked: 'lock', nosession: 'alert', offline: 'wifioff', queued: 'wifioff', error: 'alert', busy: 'scan' }[state] || 'scan');
    el.title.textContent = title; el.sub.textContent = sub || ''; el.meta.textContent = meta || '';
    readyTimer = setTimeout(ready, ms || 2500);
  }
  /** Tampilkan hasil (dari roster lokal atau server; bentuknya sama). */
  function render(d, silent) {
    var st = d.student || null, cls = st && st.class ? st.class : '', s = d.session || null;
    var beep = function (k) { if (!silent) SOUND[k](); };
    switch (d.state) {
      case 'ok':
        beep('ok'); flash('ok');
        show('ok', st.name, d.message || T('Hadir'), (d.scanned_at || '') + (s ? ' · ' + s.label : ' · ' + cls), st, 2200); break;
      case 'late':
        beep('late'); flash('late');
        show('late', st.name, d.message, (d.scanned_at || '') + (s ? ' · ' + s.label : ' · ' + cls), st, 2800); break;
      case 'dup':
        beep('dup'); flash('dup');
        show('dup', st.name, d.message, s ? s.label : cls, st, 2500); break;
      case 'wrongclass':
        beep('bad'); flash('bad');
        show('wrongclass', st ? st.name : T('Salah kelas'), d.message, cls ? T('Kelas siswa: :c', { c: cls }) : '', st, 4000); break;
      case 'nosession':
        beep('mute'); flash('mute');
        show('nosession', T('Belum ada jadwal di ruangan ini'), st ? st.name + (cls ? ' · ' + cls : '') : '', T('Kehadiran tidak tersimpan'), null, 4000); break;
      case 'invalid':
        beep('bad'); flash('bad');
        show('invalid', T('Kartu tidak berlaku'), T('Hubungi TU'), '', null, 4000); break;
      case 'revoked':
        beep('bad'); flash('bad');
        show('revoked', T('Perangkat dicabut'), d.message || '', '', null, 6000); break;
      default:
        beep('bad'); flash('bad');
        show('error', T('Gagal'), d.message || T('Terjadi kesalahan di server.'), '', null, 4000);
    }
  }

  /* ---------- metrik & statistik ---------- */
  var metricsOut = [];  // menunggu dikirim ke server
  var samples = [];     // 200 scan terakhir untuk kaki halaman
  var rejected = 0;     // scan antrean yang ditolak server setelah sempat ditandai lokal
  function pct(arr, p) { if (!arr.length) return 0; var s = arr.slice().sort(function (a, b) { return a - b; }); return s[Math.max(0, Math.ceil(s.length * p / 100) - 1)]; }
  function updateStats() {
    var fb = samples.map(function (x) { return x.t_feedback; }), tot = samples.filter(function (x) { return !x.queued; }).map(function (x) { return x.t_total; });
    var srv = samples.filter(function (x) { return x.t_server > 0; }).map(function (x) { return x.t_server; });
    var parts = [];
    if (samples.length) {
      parts.push(T(':n scan', { n: samples.length }));
      parts.push(T('tanda p95 :p ms', { p: Math.round(pct(fb, 95)) }));
      if (tot.length) parts.push(T('total p95 :p ms', { p: Math.round(pct(tot, 95)) }));
      if (srv.length) parts.push(T('server :s ms', { s: (srv.reduce(function (a, b) { return a + b; }, 0) / srv.length).toFixed(1) }));
    } else { parts.push(T('Belum ada scan')); }
    if (pending.length) parts.push(T('antrean :n', { n: pending.length }));
    if (rejected) parts.push(T('ditolak server :n', { n: rejected }));
    el.stats.textContent = parts.join(' · ');
    root.setAttribute('data-queue', pending.length);
  }
  function metric(item, res, tRound) {
    var now = performance.now();
    var live = item.t0 !== null && item.session === SESSION_ID;
    var m = {
      scan_id: item.scan_id, source: item.source, result: res.state, local: item.local ? 1 : 0,
      t_decode: item.t_decode, t_feedback: item.t_feedback !== null ? item.t_feedback : (live ? Math.round(now - item.t0) : null),
      t_roundtrip: Math.round(tRound), t_total: live ? Math.round(now - item.t0) : null,
      queued: item.tries > 0 || !live || (Date.now() - item.at > 5000) ? 1 : 0, queue_wait: Date.now() - item.at
    };
    metricsOut.push(m); if (metricsOut.length > 400) metricsOut.splice(0, metricsOut.length - 400);
    samples.push({ t_feedback: m.t_feedback || 0, t_total: m.t_total || 0, t_server: res.t_server_ms || 0, queued: m.queued }); if (samples.length > 200) samples.shift();
  }

  /* ---------- scan ---------- */
  var SESSION_ID = Math.random().toString(36).slice(2); // t0 (performance.now) hanya sah selama halaman ini hidup
  var last = { code: '', at: 0 };
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var a = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  function handle(raw, source, tStart) {
    var t0 = performance.now();
    var code = String(raw || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (code.length < 6) return;
    var nowMs = Date.now();
    if (code === last.code && nowMs - last.at < 3000) return; // scanner USB/kamera sering membaca dua kali
    last = { code: code, at: nowMs };
    var item = { scan_id: uuid(), code: code, scanned_at: localIso(new Date(nowMs)), at: nowMs, source: source || 'usb', tries: 0,
      t0: t0, session: SESSION_ID, t_decode: tStart ? Math.max(0, Math.round(t0 - tStart)) : 0, t_feedback: null, local: null };
    var v = verdict(code, nowMs + clockOffset);
    if (v) {
      item.local = v.state;
      render(v);
      item.t_feedback = Math.round(performance.now() - t0);
      if (v.state === 'ok' || v.state === 'late') bump(v.session, v.state);
    } else if (online) {
      show('busy', T('Memeriksa…'), '', '', null, 8000);
    } else {
      SOUND.mute(); flash('mute');
      show('queued', T('Tersimpan di antrean'), T('Offline: dikirim otomatis saat jaringan kembali'), code, null, 2500);
      item.t_feedback = Math.round(performance.now() - t0);
    }
    shown = item.scan_id;
    pending.push(item);
    store.put(item);
    updateStats();
    flush();
  }

  /* ---------- pengirim: satuan saat lancar, batch saat menumpuk; ulangi dengan jeda bertambah ---------- */
  var sending = false, retryTimer = null, backoff = 1000;
  function wire(x) { return { scan_id: x.scan_id, code: x.code, scanned_at: x.scanned_at, metrics: { source: x.source, t_decode_ms: x.t_decode, local: x.local } }; }
  function flush() {
    if (sending || !token || !pending.length) { if (!pending.length) flushMetrics(); return; }
    sending = true; clearTimeout(retryTimer);
    var chunk = pending.slice(0, BATCH_SIZE), single = chunk.length === 1;
    var tSend = performance.now(), sentAt = localIso(new Date());
    var mOut = metricsOut.splice(0, 100);
    var req = single
      ? api(URL_SCAN, Object.assign(wire(chunk[0]), { sent_at: sentAt, prev_metrics: mOut }), 8000)
      : api(URL_BATCH, { sent_at: sentAt, scans: chunk.map(wire), metrics: mOut, queue_size: pending.length }, 20000);
    req.then(function (d) {
      var tRound = performance.now() - tSend;
      if (d._http === 401) { metricsOut = mOut.concat(metricsOut); sending = false; unpaired(d.message); return; }
      if (d._http === 403) { metricsOut = mOut.concat(metricsOut); sending = false; render({ state: 'revoked', message: d.message }); stopCamera(); return; }
      var results = single ? [d] : (d.results || []);
      if (d._http >= 500 || (!single && !d.ok) || results.length !== chunk.length) { throw new Error('server'); }
      var done = {};
      chunk.forEach(function (item, i) { done[item.scan_id] = 1; settle(item, results[i], tRound / chunk.length); });
      pending = pending.filter(function (x) { return !done[x.scan_id]; });
      store.del(Object.keys(done));
      backoff = 1000; sending = false;
      net(true); updateStats();
      if (pending.length) { flush(); } else { flushMetricsSoon(); }
    }).catch(function () {
      metricsOut = mOut.concat(metricsOut);
      chunk.forEach(function (item) { item.tries++; });
      sending = false;
      net(false); updateStats();
      // Scan yang belum dapat keputusan lokal: beri tahu bahwa ia aman di antrean
      var cur = chunk.filter(function (x) { return x.scan_id === shown && !x.local; })[0];
      if (cur) { SOUND.mute(); flash('mute'); show('queued', T('Tersimpan di antrean'), T('Offline: dikirim otomatis saat jaringan kembali'), cur.code, null, 2500); cur.t_feedback = Math.round(performance.now() - cur.t0); }
      retryTimer = setTimeout(flush, backoff); backoff = Math.min(backoff * 2, 15000);
    });
  }
  /** Cocokkan jawaban server dengan tanda lokal. Server selalu menang. */
  function settle(item, res, tRound) {
    metric(item, res, tRound);
    var good = res.state === 'ok' || res.state === 'late' || res.state === 'dup';
    var localGood = item.local === 'ok' || item.local === 'late' || item.local === 'dup';
    var live = item.scan_id === shown;
    if (res.session && good && res.student) { // selaraskan daftar hadir lokal
      (roster.sessions || []).forEach(function (s) { if (s.id === res.session.id || (s.class_id === res.session.class_id && s.status === 'open' && s.from_ms <= item.at + clockOffset && item.at + clockOffset < s.ends_ms)) { present[s.key] = present[s.key] || {}; present[s.key][res.student.id] = true; } });
    }
    if (!item.local) {
      if (live) { render(res); if (res.state === 'ok' || res.state === 'late') bump(sessionKey(res.session), res.state); }
      else if (res.state === 'ok' || res.state === 'late') { bump(sessionKey(res.session), res.state); }
      return;
    }
    if (localGood && !good) { // ditandai hijau/kuning tetapi server menolak (kartu baru dicabut, sesi dibatalkan, pindah kelas)
      var st = byCode[item.code];
      (roster.sessions || []).forEach(function (s) { if (st && present[s.key]) delete present[s.key][st[1]]; });
      rejected++;
      if (live || Date.now() - item.at < 15000) { render(res); }
      refreshRoster();
      return;
    }
    if (live && res.state !== item.local && good) { render(res, true); } // mis. lokal "Hadir", server "Sudah tercatat 07:02": perbarui tanpa bunyi lagi
  }
  function sessionKey(s) {
    if (!s) return null;
    var m = (roster.sessions || []).filter(function (x) { return x.id === s.id; })[0];
    return { key: m ? m.key : 'id:' + s.id };
  }
  var metricsTimer = null;
  function flushMetricsSoon() { clearTimeout(metricsTimer); metricsTimer = setTimeout(flushMetrics, 4000); }
  function flushMetrics() {
    if (sending || !token || !metricsOut.length || pending.length) return;
    var mOut = metricsOut.splice(0, 200);
    api(URL_BATCH, { sent_at: localIso(new Date()), scans: [], metrics: mOut, queue_size: 0 }, 8000)
      .then(function (d) { if (!d.ok) metricsOut = mOut.concat(metricsOut); })
      .catch(function () { metricsOut = mOut.concat(metricsOut); });
  }

  /* ---------- scanner USB (keyboard wedge): ketikan cepat + Enter, di mana pun fokusnya ---------- */
  var buf = '', bufAt = 0, bufStart = 0;
  document.addEventListener('keydown', function (e) {
    if (el.pair && !el.pair.hidden) return;
    if (e.target === el.code) return;
    var now = Date.now();
    if (now - bufAt > 400) { buf = ''; }
    bufAt = now;
    if (e.key === 'Enter') {
      if (buf.length >= 6) { e.preventDefault(); var c = buf; buf = ''; if (e.target === el.input) el.input.value = ''; handle(c, 'usb', bufStart); }
      return;
    }
    if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) { if (!buf) bufStart = performance.now(); buf += e.key; }
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
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { camMsg((icons.alert || '') + '<span>' + T('Kamera butuh HTTPS atau localhost.') + '</span>'); return; }
    camMsg('<span class="spin"></span>' + T('Menyalakan kamera…'));
    scanner = scanner || new Html5Qrcode('reader', { verbose: false });
    var cfg = { fps: 15, qrbox: function (w, h) { var s = Math.floor(Math.min(w, h) * 0.7); return { width: s, height: s }; }, aspectRatio: 1.333 };
    var frameAt = 0;
    var onScan = function (text) { handle(text, 'camera', frameAt || performance.now()); frameAt = 0; };
    var pick = cams.length ? { deviceId: { exact: cams[camIdx].id } } : { facingMode: 'environment' };
    scanner.start(pick, cfg, onScan, function () { frameAt = performance.now(); })
      .then(function () { camRunning = true; camMsg(''); el.camRetry.hidden = true; return Html5Qrcode.getCameras(); })
      .then(function (list) { cams = list || []; el.camSwitch.hidden = cams.length < 2; })
      .catch(function (err) {
        camRunning = false;
        var denied = /NotAllowed|Permission/i.test(String(err));
        camMsg((icons.alert || '') + '<span>' + (denied ? T('Akses kamera ditolak. Izinkan kamera di pengaturan browser.') : T('Kamera tidak tersedia. Scanner USB tetap bisa dipakai.')) + '</span>');
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

  /* ---------- service worker: halaman kiosk tetap bisa dibuka tanpa jaringan ---------- */
  if ('serviceWorker' in navigator && A('sw')) {
    navigator.serviceWorker.register(A('sw'), { scope: A('scope') }).catch(function () { /* http biasa (bukan localhost/https): lewati */ });
  }

  /* ---------- mulai ---------- */
  window.__kiosk = { queue: function () { return pending.length; }, samples: function () { return samples.slice(); }, flush: flush, rosterSize: function () { return (roster.students || []).length; }, rejected: function () { return rejected; } };
  if (token) { start(); } else { showPair(); }
})();
