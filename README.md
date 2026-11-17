# Absensi Sekolah (QR)

Web app absensi siswa: siswa menempelkan kartu QR ke scanner di kelas, kehadiran tercatat otomatis, dan guru, wali kelas, TU, serta kepala sekolah melihat rekapnya. Dibangun dengan **PHP 8.1+ (OOP, tanpa framework)** dan **MySQL/MariaDB** (SQLite untuk pengujian), diturunkan dari [smartcity-attendance](https://github.com/Alfauzi-S/smartcity-attendance).

Dokumen acuan: **PRD Absensi QR Siswa** (dokumen proyek) berisi peran, alur scan, target performa, 8 fase pengerjaan, dan rencana tes per fase.

## Status pengerjaan

| Fase | Isi | Status |
| --- | --- | --- |
| 0 | Salin kode asal, buang modul event/sertifikat yang tidak dipakai, CI, baseline kecepatan | Selesai |
| 1 | Data master sekolah (tahun ajaran, kelas, siswa, guru, jadwal), 4 peran + wali kelas, impor Excel/CSV | Selesai |
| 2 | Kartu QR siswa (kode acak 12 karakter berversi), foto siswa, cetak PDF CR80 massal | Selesai |
| 3 | Scanner kiosk dan inti absensi (`POST /api/scan`) | Selesai |
| 4 | Performa, roster lokal, antrean offline, metrik kecepatan scan | Selesai |
| 5 | Izin/sakit, koreksi, rekap, dashboard | Selesai |
| 6 | Notifikasi WhatsApp dan portal orang tua (opsional, diatur pengurus) | Selesai |
| 7 | Pilot dan go-live: cadangan harian, pemulihan, kesehatan sistem, modul pilot, runbook, UAT, buku panduan | Perangkat siap (PR ini); pilot nyata di sekolah menunggu jadwal |

Catatan Fase 1: seluruh modul event/peserta dari kode asal sudah diganti dengan data sekolah. Skema baru (`database/schema.*.sql`): `settings`, `users`, `academic_years`, `semesters`, `rooms`, `classes`, `students`, `class_members`, `subjects`, `periods`, `schedules`, `holidays`, `audit_logs`.

### Peran dan akses (Fase 1)

| Peran | Boleh |
| --- | --- |
| Super Admin | Semua, termasuk akun pengguna, pengaturan sekolah, audit log |
| TU / Staff | Kelola tahun ajaran, kelas, siswa, ruangan, mapel, jam, libur, jadwal, impor; ekspor |
| Kepala Sekolah | Melihat semua kelas, siswa, jadwal; ekspor. Tidak mengubah data |
| Guru | Melihat kelas yang diajar (jadwal semester aktif) atau diwalikan. Kelas lain ditolak (403) |
| Wali kelas | Guru yang tercatat di `classes.homeroom_teacher_id`; mendapat izin `class:manage` di kelas itu (dipakai Fase 5 untuk izin/koreksi) |

Pengaturan sekolah yang dipilih pengurus (PRD 4.3) disimpan di tabel `settings`: mode absensi (masuk/pulang, per jam, keduanya), jam masuk/pulang dan batas terlambat, jenis scanner (USB/kamera), hari libur mingguan, notifikasi WA (mati secara bawaan).

### Pilot dan go-live (Fase 7)

- **Buku panduan**: [docs/Manual-Book-Absensi-Sekolah.pdf](docs/Manual-Book-Absensi-Sekolah.pdf) (13 bab, 48 tangkapan layar, format manual book lama). Sumber dan cara membuat ulang: [docs/manual/](docs/manual/README.md).
- **Runbook**: [docs/go-live.md](docs/go-live.md) (persiapan, pilot 2 kelas, kriteria lulus, pelatihan, rollout, pemantauan minggu pertama, rencana mundur). **Skenario UAT** per peran dengan lembar tanda tangan: [docs/uat-skenario.md](docs/uat-skenario.md).
- **Cadangan harian otomatis** (`storage/backups/absensi-*.zip`, 14 terakhir): data semua tabel dalam format netral (JSONL), foto siswa, dan lampiran izin. Dibuat oleh tugas berkala setelah pukul 01:30, atau manual dari Kesehatan Sistem / `php bin/backup.php`. Pulihkan dengan `php bin/restore.php <zip>`; bisa lintas SQLite ↔ MySQL, jumlah baris setiap tabel dicocokkan.
- **Tugas berkala**: `bin/close-sessions.php` kini menjalankan tutup sesi, kirim WhatsApp, bersih-bersih, dan cadangan harian, lalu mencatat detak jantung. Docker punya service `scheduler` (`bin/scheduler.php`, tiap 5 menit) sehingga tidak perlu cron.
- **Kesehatan Sistem** (`/admin/kesehatan`, Super Admin): database, migrasi, scheduler, cadangan, disk, sesi menggantung, perangkat scanner (terlihat, antrean, selisih jam), kecepatan hari ini, WhatsApp; unduh cadangan. **`/healthz`** (tanpa login, tanpa data pribadi) untuk pemantau uptime: 200 atau 503.
- **Pilot dan Go-live** (`/admin/pilot`): pilih 2 kelas dan rentang tanggal; p95 waktu total scan USB per kelas vs 300 ms; **banding** absen manual guru vs sistem per tanggal (isi di layar atau impor CSV/Excel, selisih wajib dijelaskan); ekspor Excel (Kecepatan, Akurasi, Masukan).
- **Kirim Masukan** (`/admin/masukan`, semua peran): topik, nilai 1–5, pesan; Super Admin menandai selesai.
- Tes: `tests/Unit/BackupTest.php` (cadangan → pulih identik termasuk berkas, prune, path aman, jadwal cadangan, detak jantung), `tests/Unit/PilotTest.php` (kecepatan per kelas, banding, impor CSV, akurasi lulus/gagal); `tests/ops/restore-drill.sh` (server lama → cadangan → server baru, ±320 ribu baris; di CI dipulihkan ke MariaDB, < 30 menit).

### Notifikasi WhatsApp dan portal orang tua (Fase 6)

Keduanya **mati secara bawaan** dan dinyalakan Super Admin di Pengaturan.

- **Notifikasi WhatsApp** (Pengaturan → Notifikasi WhatsApp; riwayat di menu **Notifikasi WA**): kirim untuk Alpa, Terlambat, atau keduanya. Penyedia: **Sandbox** (tidak mengirim, hanya mencatat ke `storage/logs/wa-sandbox.log`; untuk uji coba), **Fonnte** (isi token), atau **Webhook JSON** untuk gateway lain (POST `{"phone","message"}` ke URL https dengan `Authorization: Bearer <token>`). Teks pesan bisa diubah dengan isian `{nama} {kelas} {tanggal} {jam} {menit} {sekolah}`.
- **Aturan kirim**: paling banyak **satu pesan per siswa per hari** (dijaga indeks unik di database, aman walau cron dan tombol "Kirim sekarang" berjalan bersamaan). Terlambat diantrekan saat scan bila status harian siswa Terlambat; Alpa diantrekan setelah semua sesi kelas itu hari ini ditutup (mode per jam: setelah jam pelajaran terakhir). Sebelum dikirim status diperiksa ulang; bila sudah berubah (izin dicatat, koreksi, scan offline masuk belakangan) pesan dilewati, dan pesan Alpa yang belum terkirim diganti menjadi Terlambat. Gagal kirim (jaringan, 5xx, 429) **dicoba ulang** setelah 1, 5, 15, 60 menit; setelah 5 kali menjadi Gagal dan bisa dikirim ulang dari halaman Notifikasi. Kesalahan permanen (token salah, nomor ditolak) tidak diulang. Nomor kosong/tidak valid → Dilewati.
- **Pengiriman** berjalan dari cron `bin/close-sessions.php` (tiap 5 menit, sama seperti penutupan sesi) atau, sebagai cadangan, setelah jawaban kiosk `/api/device/me` terkirim. Nomor HP orang tua hanya dibaca di server dan **tidak pernah dikirim ke perangkat scanner**; di layar admin nomor disamarkan (0812****1049).
- **Portal orang tua** (`/portal`, nyalakan di Pengaturan → Portal orang tua): orang tua masuk dengan **NIS + kode akses 8 karakter** lalu melihat riwayat kehadiran anaknya per bulan (status, jam scan, menit terlambat, keterangan izin) dan ringkasan semester. Kode dibuat TU/Super Admin di halaman siswa (ditampilkan sekali) atau per kelas sebagai **CSV** untuk dibagikan; disimpan sebagai hash, membuat kode baru membatalkan yang lama. Satu ponsel bisa membuka beberapa anak (kakak-adik). Membuka anak lain dengan mengganti ID di alamat → **403** dan tercatat di audit log. Percobaan masuk dibatasi (10 per IP dan 5 per NIS tiap 15 menit).
- Tes: `tests/Unit/NotifierTest.php` (**100 siswa alpa di gateway sandbox → tepat 100 pesan, tidak ada yang ganda, 25 nomor yang gagal dicoba ulang lalu terkirim**; gagal permanen setelah 5 kali; terlambat diantrekan sekali; status berubah → dilewati; klaim bersamaan), `tests/Unit/PortalTest.php` (kode hanya hash, kode lama batal, anak lain ditolak), `tests/smoke.sh` (pesan uji sandbox, kode portal tampil sekali, CSV per kelas, masuk portal, **ganti ID → 403**, roster kiosk tanpa nomor HP).

### Izin, koreksi, rekap, dashboard (Fase 5)

- **Izin/Sakit/Dispensasi** (`/admin/izin`): dicatat TU atau wali kelas untuk rentang tanggal (maks. 60 hari), keterangan wajib, lampiran foto/PDF surat opsional (≤ 3 MB, disimpan di `storage/leaves/`, hanya bisa dibuka pengguna yang berhak). Langsung berlaku, termasuk untuk sesi yang sudah lewat. Bisa dibatalkan dengan alasan; status kembali ke hasil scan atau Alpa. Kepala Sekolah hanya melihat.
- **Prioritas status** (PRD bagian 9): koreksi manual guru/wali kelas > Izin/Sakit/Dispensasi > scan (Hadir/Terlambat) > Alpa otomatis. Jam scan tetap disimpan walau statusnya kalah prioritas. Penutupan sesi memberi siswa berizin status izinnya, bukan Alpa.
- **Koreksi status** di halaman sesi (guru pengampu, wali kelas, TU, Super Admin): pilih status baru + alasan wajib; bisa dihapus lagi (kembali ke surat/scan). Setiap perubahan (scan tersinkron, izin, batal izin, koreksi) tercatat di tabel `status_history` dan tampil di halaman sesi dan riwayat siswa.
- **Rekap** (`/admin/rekap`, format PRD 4.4): bulanan per kelas (kode H/T/S/I/A/D per tanggal, libur diarsir, jumlah per kode, % hadir, hadir per hari), harian per kelas (satu kolom per jam/Masuk/Pulang), semester per kelas (per bulan + total), riwayat per siswa (jam scan, menit terlambat, jam alpa, keterangan surat/koreksi), ringkasan sekolah per kelas. Unduh **Excel** (kop sekolah, A4 landscape muat satu halaman lebar, tanda tangan wali kelas dan kepala sekolah) atau **CSV**; teks yang diawali `= + - @` diberi tanda kutip (aman dari formula injection). Rekap semester **seluruh sekolah** dalam satu file (satu sheet per kelas + ringkasan).
- **Aturan status harian**: sesi yang dihitung = jam pelajaran dan Masuk (Pulang, sesi tambahan, dan jam kosong tidak). Hadir bila sesi pertama yang diikuti Hadir; Terlambat bila scan pertamanya terlambat atau baru datang di jam berikutnya; tidak hadir sama sekali → Izin/Sakit/Dispensasi bila ada, selain itu Alpa. Hari efektif = tanggal dengan sesi terhitung yang sudah ditutup dan bukan hari libur. % hadir = (H + T) ÷ hari efektif.
- **Dashboard**: kepala sekolah/TU/Super Admin melihat hadir/izin/alpa hari ini, % hadir bulan ini, kelas dengan kehadiran terendah, dan siswa alpa ≥ 3 hari semester ini; wali kelas melihat yang sama untuk kelasnya.
- **Sesi untuk kelas yang scannernya mati**: cron `bin/close-sessions.php` kini juga membuat sesi hari ini untuk ruangan yang punya perangkat aktif, sehingga Alpa tetap tercatat walau tablet tidak menyala seharian.
- **Data demo** kini berisi riwayat kehadiran ±30 hari terakhir (mode masuk/pulang, sebagian terlambat, sakit/izin bersurat, alpa) agar rekap dan dashboard langsung berisi.
- Hak akses: rekap kelas untuk Super Admin, Kepsek, TU, dan wali kelas kelasnya saja (guru mapel dan wali kelas lain → 403); ringkasan sekolah untuk Super Admin, Kepsek, TU.
- Tes: `tests/Unit/LeaveRecapTest.php` (prioritas status dan riwayat, koreksi tanpa alasan ditolak, tutup sesi dengan izin, aturan status harian, **rekap 1 bulan 3 kelas dibandingkan hitungan manual: cocok 100%**, daftar alpa ≥ 3 versi SQL = versi rekap, formula injection, hak akses). `php tests/perf/rekap.php`: rekap semester seluruh sekolah (30 kelas, 153.360 baris kehadiran) **0,3 detik** (target < 30 detik). `tests/smoke.sh`: izin menimpa scan, koreksi tanpa alasan ditolak, unduhan Excel/CSV, akses per peran.

### Performa dan offline (Fase 4)

- **Roster lokal** (`GET /api/roster`): kiosk menyimpan kode kartu, nama, dan sesi ruangannya hari itu, lalu menampilkan tanda Hadir/Terlambat/Sudah tercatat langsung dari perangkat tanpa menunggu server. Jawaban server tetap yang menentukan dan mengoreksi layar bila berbeda. Roster diperbarui tiap menit bila versinya berubah.
- **Antrean offline**: setiap scan masuk antrean IndexedDB dengan `scan_id` unik, bertahan walau halaman ditutup atau tablet mati. Saat tersambung lagi dikirim per 50 lewat `POST /api/scan/batch`. Mengirim ulang batch yang sama tidak membuat catatan ganda. Waktu kehadiran = waktu scan di perangkat (dikoreksi selisih jam perangkat terhadap server), bukan waktu sinkron, diterima sampai 3 hari ke belakang. Scan yang baru tersinkron setelah sesi ditutup menggantikan Alpa otomatis.
- **Kiosk tetap terbuka saat offline** lewat service worker (`public/kiosk-sw.js`) yang menyimpan halaman, aset, dan foto.
- **Server**: tanpa sesi PHP untuk request perangkat, kartu + siswa + kelas dalam satu query, sesi aktif per ruangan di-cache per menit (`App\Core\Cache`, memakai APCu bila terpasang), log scan ditulis setelah jawaban dikirim.
- **Metrik** (`scan_metrics`): kiosk mengukur `t_decode`, `t_feedback`, `t_roundtrip`, `t_total` per scan dan mengirimnya menumpang request berikutnya; `t_server` dari log server.
- **Dashboard Kecepatan Scan** (`/admin/kecepatan`, Super Admin): p50/p95 per perangkat dan per hari, perangkat lambat, isi antrean offline, selisih jam perangkat.
- Hasil uji: [docs/laporan-performa-fase-4.md](docs/laporan-performa-fase-4.md). Ringkas: 1.000 scan USB tanda p95 < 1 ms dan tersimpan p95 16 ms; offline 500 scan dengan muat ulang di tengah → 500 tercatat, 0 ganda; 100 scan/detik `t_server` p95 3 ms, 0% error.

### Scanner kiosk dan inti absensi (Fase 3)

- **Perangkat scanner** (`/admin/perangkat`, Super Admin): satu perangkat per ruangan. Super Admin membuat perangkat → kode pasangan 6 digit (15 menit) → di kiosk (`/kiosk`) kode dimasukkan sekali → kiosk menyimpan token (server hanya menyimpan hash). Perangkat bisa dipasang ulang atau dicabut; token dicabut langsung ditolak (401).
- **Sesi absensi** dibuat otomatis saat scanner butuh: mode *per jam* mengikuti jadwal ruangan (ruangan jadwal, atau ruang kelas bila jadwal tidak menyebut ruangan), scan diterima 10 menit sebelum jam mulai sampai akhir jam; mode *masuk/pulang* mengikuti jendela jam di pengaturan untuk kelas yang menempati ruangan; mode *keduanya* mencatat ke keduanya. Hari libur/akhir pekan: tidak ada sesi. Slot unik (`sessions.slot_key`) menjamin dua scanner tidak membuat sesi ganda.
- **`POST /api/scan`** (header `X-Device-Token`, body `{scan_id, code, scanned_at, metrics}`): satu request, satu `INSERT` atomik dengan `UNIQUE (session_id, student_id)`. Hasil: `ok`, `late` (Terlambat N menit, dihitung per menit sejak jam mulai; toleransi dari pengaturan, bawaan 10), `dup` (Sudah tercatat HH:MM), `wrongclass` (Bukan kelas X), `invalid` (kartu tidak ada/dicabut/siswa nonaktif), `nosession`. `scan_id` yang sama dijawab sama tanpa mencatat ulang (idempoten). Semua scan, termasuk yang ditolak, masuk `scan_logs` beserta `t_server_ms`.
- **Kiosk** (`/kiosk`, tanpa login): scanner USB/Bluetooth (keyboard wedge: ketikan cepat + Enter, fokus di mana pun), kamera (html5-qrcode, bila pengaturan mengizinkan), bunyi berbeda per hasil (WebAudio, tanpa file), warna layar, foto/inisial siswa, kembali siap scan otomatis (2–4 detik), penghitung `t_total` (ketik → tampil) rata-rata/p95 di kaki halaman. Perangkat yang dicabut/401 otomatis kembali ke layar pairing.
- **Sesi** (`/admin/sesi`): daftar per tanggal (guru hanya kelasnya), halaman live per sesi (polling 5 detik), **tutup sesi → siswa belum scan = Alpa**, **jam kosong** (dibatalkan, tidak ada Alpa), buka kembali, tandai hadir manual, **sesi tambahan** di luar jadwal (ekstrakurikuler, guru pengganti). Penutupan otomatis 5 menit setelah jam berakhir lewat `bin/close-sessions.php` (cron tiap 5 menit) atau, sebagai cadangan, saat kiosk memanggil `/api/device/me`.
- Hasil ukur lokal (SQLite, server bawaan PHP): server 1–3 ms per scan; E2E Playwright 36 siswa lewat ketikan USB: `t_total` rata-rata 13 ms, p95 21 ms (target PRD ≤ 300 ms); 50 scan siswa yang sama secara paralel → tepat 1 baris, 49 `dup`.
- Tes: `tests/Unit/SessionResolverTest.php` (batas menit, pergantian jam, libur/akhir pekan, ruangan jadwal vs ruang kelas, mode masuk/pulang/keduanya, jam kosong, sesi tambahan), `tests/Unit/ScanServiceTest.php` (menit ke-0/10/11, ok/dup/late/wrongclass/invalid/nosession, idempoten, 50 scan ganda, tutup sesi → Alpa, siklus token perangkat), `tests/smoke.sh` (pairing, 401/403, 50 scan paralel lewat HTTP, akses per peran), `tests/e2e/run.sh` (Playwright kiosk 36 siswa).

### Kartu QR (Fase 2)

Isi QR adalah **kode kartu acak 12 karakter** dari 31 karakter tanpa yang mirip (tanpa 0/O, 1/I/L), bukan NIS, sehingga tidak bisa ditebak. Tabel `cards` menyimpan versi per siswa; menerbitkan kartu pengganti (hilang/rusak) menaikkan versi dan mencabut kartu lama, sehingga scanner langsung menolaknya. Menu **Kartu QR** menerbitkan kartu massal per kelas atau seluruh sekolah dan membuat PDF (TCPDF): 10 kartu CR80 85,6 × 54 mm per halaman A4 dengan garis potong, QR level M 26 mm, nama, NIS, kelas, dan foto. Foto siswa diunggah di form siswa, dipotong 3:4 dan diperkecil dengan GD, disimpan di `storage/photos/` (di luar `public/`) dan dilayani lewat rute yang memeriksa hak akses kelas.

Impor massal (`Impor Data`): siswa (`nis, nisn, nama, jk, kelas, tanggal_lahir, nama_ortu, hp_ortu`) dan jadwal (`kelas, hari, jam_mulai, jam_selesai, mapel, nip_guru, ruangan`) dari .xlsx/.csv tanpa pustaka tambahan; baris yang gagal dilaporkan per nomor baris, baris lain tetap masuk.

## Menjalankan secara lokal

```bash
cp config/config.example.php config/config.php   # ubah driver menjadi 'sqlite' untuk uji cepat
php -S localhost:8000 -t public public/index.php
```

Buka `http://localhost:8000/install`, buat akun Super Admin, centang data demo bila perlu. Kamera scanner berjalan di `localhost` tanpa HTTPS.

Dengan Docker (Nginx + PHP-FPM + MariaDB):

```bash
cp .env.example .env          # isi DB_PASSWORD dan DB_ROOT_PASSWORD
docker compose up -d --build  # lalu buka http://<server>:8090/install
```

## Pengujian

| Perintah | Isi |
| --- | --- |
| `phpunit` | Tes unit (SQLite sementara): matriks akses per peran, validator, impor dengan baris rusak, bentrok jadwal, pengaturan, kartu QR (100.000 kode tanpa tabrakan, ganti kartu, PDF 36 kartu < 5 detik) |
| `bash tests/smoke.sh` | Instal otomatis dengan data demo (30 kelas, 1.080 siswa, 60 guru), buka semua halaman sebagai Super Admin/TU/Kepsek/Guru, impor CSV lewat form, terbitkan dan cetak kartu QR, pastikan tidak ada 500, halaman lama 404, dan kelas lain ditolak 403 |
| `bash tests/e2e/run.sh` | Playwright: kiosk 36 siswa lewat ketikan scanner USB |
| `php tests/perf/rekap.php` | Uji ekspor rekap semester seluruh sekolah (isi riwayat 100 hari ke database instalasi demo, lalu ukur); target < 30 detik |
| `bash tests/perf/run.sh` | Uji performa Fase 4 (latensi 1.000 scan, jaringan lambat, offline 500 scan, batch 3 kali, beban bertahap); butuh node + Playwright |
| `bash tests/ops/restore-drill.sh` | Uji pulih Fase 7: server lama SQLite dengan riwayat, cadangan, pulihkan ke server baru (SQLite, atau MariaDB dengan `DRILL_TARGET=mysql`), login dan buka halaman, catat waktunya |
| `node tests/perf/baseline-old-flow.js` | Pengukuran kecepatan alur scan lama (Playwright); hasilnya di [docs/baseline-fase-0.md](docs/baseline-fase-0.md) |

CI (GitHub Actions) menjalankan lint PHP, PHPUnit, smoke test, uji pulih cadangan ke MariaDB, dan build image Docker pada setiap PR.

## Struktur

```
app/
  Core/          Router, Database (PDO), Auth, View, RateLimiter, Migrator
  Models/        Setting, User, AcademicYear, Semester, SchoolClass, Student, Card, Master (ruangan/mapel/jam/libur), Schedule, AuditLog
  Services/      Access (izin per peran), Validator, Importer, Spreadsheet (baca CSV/XLSX), Exporter (CSV/XLSX), CardPdf, Photo, DemoSeeder
  Controllers/   Auth, Install, Dashboard, Settings, Account, Audit, AcademicYear, Class, Student, Card, Master, Schedule, Import
  Views/         layouts, partials, auth, install, errors, admin/*, guru/*
bin/             migrate.php, close-sessions.php, scheduler.php, backup.php, restore.php
config/          config.example.php -> salin menjadi config.php
database/        schema.{mysql,sqlite}.sql, migrations/
docker/          PHP-FPM Dockerfile, Nginx, entrypoint, generator config dari .env
public/          index.php (front controller), assets
tests/           bootstrap.php, Unit/, smoke.sh, perf/
vendor/          TCPDF (disertakan, untuk cetak kartu QR)
```

## Keamanan yang dipertahankan dari kode asal

Password `password_hash`, sesi aman, CSRF di setiap POST, rate limit login, akses deny-by-default yang dicek di server (termasuk id kelas/siswa di URL), PDO prepared statements, output di-escape, ekspor CSV aman dari formula injection, audit log.

## Pustaka pihak ketiga

- [html5-qrcode](https://github.com/mebjas/html5-qrcode) 2.3.8 (Apache-2.0): scanner kamera
- [qrcodejs](https://github.com/davidshimjs/qrcodejs) 1.0.0 (MIT): gambar QR
- [TCPDF](https://github.com/tecnickcom/TCPDF) 6.11.4 (LGPL-3.0-or-later): PDF kartu QR
