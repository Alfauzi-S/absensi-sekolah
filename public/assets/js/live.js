/* Halaman sesi live: perbarui daftar siswa tanpa muat ulang (polling 5 detik selama sesi terbuka). */
(function () {
  'use strict';
  var box = document.getElementById('live-counts');
  if (!box) return;
  var url = box.getAttribute('data-live');
  var every = parseInt(box.getAttribute('data-poll') || '0', 10);
  if (!every) return;
  var T = window.T || function (s) { return s; };
  var LABEL = { H: ['Hadir', 'ok'], T: ['Terlambat', 'warn'], I: ['Izin', 'info'], S: ['Sakit', 'info'], D: ['Dispensasi', 'teal'], A: ['Alpa', 'bad'] };
  var METHOD = { scan: 'Scan', manual: 'Manual', auto: 'Otomatis' };
  var statusEl = document.getElementById('live-status');
  var failures = 0;

  function badge(st, late) {
    if (!st) return '<span class="bdg bdg-mute">' + T('Belum') + '</span>';
    var l = LABEL[st] || [st, 'mute'];
    var h = '<span class="bdg bdg-' + l[1] + '"><i></i>' + T(l[0]) + '</span>';
    if (st === 'T') h += ' <span class="muted small">' + T(':n menit', { n: late }) + '</span>';
    return h;
  }
  function apply(d) {
    var present = d.counts.H + d.counts.T, excused = d.counts.I + d.counts.S + d.counts.D;
    var set = function (k, v) { var el = box.querySelector('[data-c="' + k + '"]'); if (el) el.textContent = v; };
    set('present', present); set('T', d.counts.T); set('A', d.counts.A); set('missing', Math.max(0, d.total - present - d.counts.A - excused));
    d.roster.forEach(function (r) {
      var tr = document.querySelector('#live-roster tr[data-sid="' + r.id + '"]');
      if (!tr) return;
      var st = tr.querySelector('[data-f="status"]');
      var was = tr.getAttribute('data-st') || '';
      if (was !== (r.status || '')) {
        st.innerHTML = badge(r.status, r.late);
        tr.querySelector('[data-f="at"]').textContent = r.at || '—';
        tr.querySelector('[data-f="method"]').textContent = r.method ? T(METHOD[r.method] || r.method) : '';
        var act = tr.querySelector('[data-f="act"]'); if (act && r.status) act.querySelectorAll('.js-manual').forEach(function (x) { x.remove(); });
        tr.setAttribute('data-st', r.status || '');
        tr.style.background = 'var(--ok-bg)'; setTimeout(function () { tr.style.background = ''; }, 1500);
      }
    });
    var upd = document.getElementById('live-updated');
    if (upd) upd.textContent = T('Diperbarui :t', { t: (d.server_time || '').slice(11, 19) });
    if (d.status !== 'open') { location.reload(); }
  }
  function tick() {
    if (document.hidden) return;
    fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { if (r.status === 401) { location.reload(); } return r.json(); })
      .then(function (d) { failures = 0; if (d && d.ok) apply(d); })
      .catch(function () { failures++; });
  }
  setInterval(tick, every);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
})();

/* Koreksi status: tombol per siswa mengisi dialog koreksi (satu dialog untuk semua baris). */
(function () {
  'use strict';
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-fix]');
    if (!b) return;
    var dlg = document.getElementById('dlg-fix');
    if (!dlg) return;
    dlg.querySelector('[name="student_id"]').value = b.getAttribute('data-fix');
    dlg.querySelector('[data-fix-name]').textContent = b.getAttribute('data-name') || '';
    var cur = b.getAttribute('data-st') || '';
    var sel = dlg.querySelector('[name="status"]');
    sel.value = cur && cur !== sel.value ? (cur === 'A' ? 'H' : 'A') : sel.value;
    var reset = dlg.querySelector('option[value="reset"]');
    if (reset) reset.disabled = b.getAttribute('data-corrected') !== '1';
    dlg.querySelector('[name="reason"]').value = '';
  }, true);
})();
