# Absensi Sekolah (QR)

Web app absensi siswa: siswa menempelkan kartu QR ke scanner di kelas, kehadiran tercatat otomatis, dan guru, wali kelas, TU, serta kepala sekolah melihat rekapnya. Dibangun dengan **PHP 8.1+ (OOP, tanpa framework)** dan **MySQL/MariaDB** (SQLite untuk pengujian), diturunkan dari [smartcity-attendance](https://github.com/Alfauzi-S/smartcity-attendance).

Dokumen acuan: **PRD Absensi QR Siswa** (dokumen proyek) berisi peran, alur scan, target performa, 8 fase pengerjaan, dan rencana tes per fase.

## Status pengerjaan

| Fase | Isi | Status |
| --- | --- | --- |
| 0 | Salin kode asal, buang modul event/sertifikat yang tidak dipakai, CI, baseline kecepatan | Selesai |
| 1 | Data master sekolah (tahun ajaran, kelas, siswa, guru, jadwal), 4 peran + wali kelas, impor Excel/CSV | Selesai |
| 2 | Kartu QR siswa (kode acak 12 karakter berversi), foto siswa, cetak PDF CR80 massal | Selesai (PR ini) |
| 3 | Scanner kiosk dan inti absensi (`POST /api/scan`) | Belum |
| 4 | Performa, roster lokal, antrean offline, metrik kecepatan scan | Belum |
| 5 | Izin/sakit, koreksi, rekap, dashboard | Belum |
| 6 | Notifikasi WhatsApp dan portal orang tua (opsional, diatur pengurus) | Belum |
| 7 | Pilot dan go-live | Belum |

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
| `node tests/perf/baseline-old-flow.js` | Pengukuran kecepatan alur scan lama (Playwright); hasilnya di [docs/baseline-fase-0.md](docs/baseline-fase-0.md) |

CI (GitHub Actions) menjalankan lint PHP, PHPUnit, smoke test, dan build image Docker pada setiap PR.

## Struktur

```
app/
  Core/          Router, Database (PDO), Auth, View, RateLimiter, Migrator
  Models/        Setting, User, AcademicYear, Semester, SchoolClass, Student, Card, Master (ruangan/mapel/jam/libur), Schedule, AuditLog
  Services/      Access (izin per peran), Validator, Importer, Spreadsheet (baca CSV/XLSX), Exporter (CSV/XLSX), CardPdf, Photo, DemoSeeder
  Controllers/   Auth, Install, Dashboard, Settings, Account, Audit, AcademicYear, Class, Student, Card, Master, Schedule, Import
  Views/         layouts, partials, auth, install, errors, admin/*, guru/*
bin/             migrate.php
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
