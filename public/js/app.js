/* Interaksi kecil untuk seluruh halaman: menu HP, baris tabel bisa diklik, konfirmasi, simpan status otomatis. */
(function () {
    var shell = document.querySelector('[data-shell]');
    document.querySelectorAll('[data-open-nav]').forEach(function (b) { b.addEventListener('click', function () { shell.classList.add('nav-open'); }); });
    document.querySelectorAll('[data-close-nav]').forEach(function (b) { b.addEventListener('click', function () { shell.classList.remove('nav-open'); }); });

    document.querySelectorAll('tr[data-href]').forEach(function (tr) {
        tr.addEventListener('click', function (e) {
            if (e.target.closest('a, button, input, select, label, form')) return;
            window.location = tr.dataset.href;
        });
    });

    // Konfirmasi tanpa dialog bawaan browser: klik pertama mengubah tombol menjadi "Yakin?".
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.confirmed) return;
            e.preventDefault();
            var btn = e.submitter || document.querySelector('[form="' + form.id + '"]') || form.querySelector('button');
            if (!btn) return;
            var old = btn.innerHTML;
            btn.innerHTML = 'Klik lagi untuk yakin';
            btn.classList.add('is-confirming');
            btn.title = form.dataset.confirm;
            var tip = document.createElement('div');
            tip.className = 'confirm-tip';
            tip.textContent = form.dataset.confirm;
            btn.insertAdjacentElement('afterend', tip);
            form.dataset.confirmed = '1';
            setTimeout(function () { delete form.dataset.confirmed; btn.innerHTML = old; btn.classList.remove('is-confirming'); tip.remove(); }, 5000);
        });
    });

    // Absensi harian: simpan status saat huruf diklik, catatan saat kolom ditinggalkan.
    var token = document.querySelector('meta[name="csrf-token"]');
    function save(form, cell) {
        var data = new FormData(form);
        cell && cell.classList.add('is-saving');
        fetch(form.action, { method: 'POST', body: data, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' } })
            .then(function (r) { if (!r.ok) throw new Error(); cell && cell.classList.add('is-saved'); })
            .catch(function () { cell && cell.classList.add('is-error'); })
            .finally(function () { cell && cell.classList.remove('is-saving'); setTimeout(function () { cell && cell.classList.remove('is-saved', 'is-error'); }, 1500); });
    }
    document.querySelectorAll('form[data-autosave]').forEach(function (form) {
        form.addEventListener('change', function () { save(form, form.closest('td')); });
    });
    document.querySelectorAll('[data-note-for]').forEach(function (inp) {
        inp.addEventListener('change', function () {
            var form = inp.closest('tr').querySelector('form[data-autosave]');
            if (!form.querySelector('input[name="status"]:checked')) { inp.classList.add('is-error'); inp.placeholder = 'Pilih status dulu'; inp.value = ''; return; }
            form.querySelector('input[name="note"]').value = inp.value;
            save(form, inp.closest('td'));
        });
    });

    document.querySelectorAll('[data-check-all]').forEach(function (all) {
        all.addEventListener('change', function () {
            all.closest('table').querySelectorAll('tbody input[type="checkbox"]').forEach(function (c) { c.checked = all.checked; });
        });
    });

    document.querySelectorAll('[data-copy-btn]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var inp = btn.parentElement.querySelector('[data-copy]');
            inp.select();
            (navigator.clipboard ? navigator.clipboard.writeText(inp.value) : Promise.reject()).then(function () { btn.textContent = 'Tersalin'; }, function () { document.execCommand('copy'); btn.textContent = 'Tersalin'; });
            setTimeout(function () { btn.textContent = 'Salin'; }, 2000);
        });
    });

    document.querySelectorAll('.dropzone input[type="file"]').forEach(function (inp) {
        inp.addEventListener('change', function () { var n = inp.closest('.dropzone').querySelector('[data-file-name]'); if (n && inp.files[0]) n.textContent = inp.files[0].name; });
    });

    var feed = document.querySelector('[data-feed]');
    if (feed) setInterval(function () {
        fetch(feed.dataset.feed, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) { return r.ok ? r.text() : null; }).then(function (h) { if (h) feed.innerHTML = h; }).catch(function () {});
    }, 15000);

    // Sesi pelajaran: scan kartu siswa dengan kamera HP guru.
    var ls = document.querySelector('[data-lesson-scan]');
    if (ls) {
        var lastCode = '', lastAt = 0;
        ls.querySelector('[data-lesson-cam]').addEventListener('click', function (ev) {
            var box = document.getElementById('lesson-reader'), out = ls.querySelector('[data-lesson-result]');
            if (!window.Html5Qrcode) return;
            box.hidden = false; ev.currentTarget.hidden = true;
            new Html5Qrcode('lesson-reader').start({ facingMode: 'environment' }, { fps: 10, qrbox: 220 }, function (code) {
                if (code === lastCode && Date.now() - lastAt < 3000) return;
                lastCode = code; lastAt = Date.now();
                fetch(ls.dataset.lessonScan, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' }, body: JSON.stringify({ code: code }) })
                    .then(function (r) { return r.json(); })
                    .then(function (r) {
                        out.className = 'lesson-scan-result tone-' + r.tone;
                        out.textContent = r.title + ' · ' + r.message;
                        if (r.ok) {
                            var radio = document.querySelector('[data-student-row="' + r.student_id + '"] input[value="H"]');
                            if (radio) radio.checked = true;
                        }
                    });
            }, function () {}).catch(function () { out.textContent = 'Kamera tidak bisa dibuka. Izinkan akses kamera di browser.'; });
        });
    }

    document.querySelectorAll('[data-toast]').forEach(function (t) { setTimeout(function () { t.classList.add('is-hiding'); }, 5000); });
})();
