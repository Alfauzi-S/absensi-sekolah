# Buku panduan (Manual Book)

Hasil: [`docs/Manual-Book-Absensi-Sekolah.pdf`](../Manual-Book-Absensi-Sekolah.pdf), format melanjutkan manual book versi lama (sampul gelap, Daftar Isi, "BAB N", "Gambar N.", footer "Halaman N dari M").

| Berkas | Isi |
| --- | --- |
| `NN-*.md` | Satu bab per berkas, diurutkan dari nomor. `# Judul` = judul bab, `##` = subbab bernomor otomatis, `![keterangan](img/x.jpg)` = gambar bernomor otomatis |
| `img/*.jpg` | Tangkapan layar dari `screenshots.js` |
| `screenshots.js` | Playwright: login per peran, kiosk (pasang, hadir, terlambat, sudah, salah kelas, tidak berlaku), ponsel, portal |
| `build.js` | Markdown → HTML → PDF (Chromium), sampul digabung dengan pypdf agar nomor halaman tidak menghitung sampul |

## Membuat ulang PDF

```bash
npm i -g marked@12 playwright   # sekali saja
pip install pypdf
NODE_PATH=$(npm root -g) node docs/manual/build.js --version 1.0 --date "November 2026"
```

## Membuat ulang tangkapan layar

Butuh instalasi dengan data demo yang berjalan (mis. `php -S 127.0.0.1:8799 -t public public/index.php`), dua kelas pilot terisi, dan satu perangkat dengan kode pasangan. Berkas `KIOSK` berisi JSON:

```json
{"pair": "123456", "codes": ["<kode kartu siswa X-1>", "..."], "wrong": "<kode kartu kelas lain>", "sick": 31, "sick_nis": "260031"}
```

```bash
BASE=http://127.0.0.1:8799 KIOSK=kiosk.json OUT=docs/manual/img DAY=2026-11-16 PILOT_DAY=2026-11-11 \
LATE_HOOK="php geser-batas-terlambat.php" NODE_PATH=$(npm root -g) node docs/manual/screenshots.js
```

`LATE_HOOK` (opsional) dijalankan setelah beberapa scan agar scan berikutnya tercatat Terlambat. Jam absen masuk di Pengaturan harus mencakup waktu pengambilan gambar, dan zona waktu browser memakai `Asia/Makassar`.
