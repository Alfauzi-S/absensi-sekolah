# Absensi Sekolah (QR)

Web app absensi siswa: siswa menempelkan kartu QR ke scanner di kelas, kehadiran tercatat otomatis, dan guru, wali kelas, TU, serta kepala sekolah melihat rekapnya. Dibangun dengan **PHP 8.1+ (OOP, tanpa framework)** dan **MySQL/MariaDB** (SQLite untuk pengujian), diturunkan dari [smartcity-attendance](https://github.com/Alfauzi-S/smartcity-attendance).

Dokumen acuan: **PRD Absensi QR Siswa** (dokumen proyek) berisi peran, alur scan, target performa, 8 fase pengerjaan, dan rencana tes per fase.

## Status pengerjaan

| Fase | Isi | Status |
| --- | --- | --- |
| 0 | Salin kode asal, buang modul event/sertifikat yang tidak dipakai, CI, baseline kecepatan | Selesai (PR ini) |
| 1 | Data master sekolah (tahun ajaran, kelas, siswa, guru, jadwal) dan 7 peran | Belum |
| 2 | Kartu QR siswa dan cetak massal | Belum |
| 3 | Scanner kiosk dan inti absensi (`POST /api/scan`) | Belum |
| 4 | Performa, roster lokal, antrean offline, metrik kecepatan scan | Belum |
| 5 | Izin/sakit, koreksi, rekap, dashboard | Belum |
| 6 | Notifikasi WhatsApp dan portal orang tua (opsional, diatur pengurus) | Belum |
| 7 | Pilot dan go-live | Belum |

Catatan Fase 0: modul **event, peserta, dan Admin Event** dari kode asal masih ada agar aplikasi tetap bisa dijalankan dan diuji; semuanya diganti dengan kelas, siswa, dan jadwal pada Fase 1. Yang sudah dibuang: registrasi publik, tiket QR publik, seluruh modul sertifikat, dan font sertifikat.

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
| `phpunit` | Tes unit aturan presensi (check-in atomik, tanpa catatan ganda, status final) memakai SQLite sementara |
| `bash tests/smoke.sh` | Instal otomatis dengan data demo, buka semua halaman utama, pastikan tidak ada 500 dan halaman yang dibuang menjawab 404 |
| `node tests/perf/baseline-old-flow.js` | Pengukuran kecepatan alur scan lama (Playwright); hasilnya di [docs/baseline-fase-0.md](docs/baseline-fase-0.md) |

CI (GitHub Actions) menjalankan lint PHP, PHPUnit, smoke test, dan build image Docker pada setiap PR.

## Struktur

```
app/
  Core/          Router, Database (PDO), Auth, View, RateLimiter, Migrator
  Models/        Event, Participant, User, StatusHistory, Assignment, AuditLog   (diganti di Fase 1)
  Services/      AttendanceService, Validator, Exporter (CSV/XLSX), Access, DemoSeeder
  Controllers/   Auth, Staff, Api, Dashboard, Event, Account, Participant, Report, Settings, Install, Audit
  Views/         layouts, partials, auth, staff, super, install, errors
bin/             migrate.php
config/          config.example.php -> salin menjadi config.php
database/        schema.{mysql,sqlite}.sql, migrations/
docker/          PHP-FPM Dockerfile, Nginx, entrypoint, generator config dari .env
public/          index.php (front controller), assets
tests/           bootstrap.php, Unit/, smoke.sh, perf/
vendor/          TCPDF (disertakan, untuk cetak kartu QR)
```

## Keamanan yang dipertahankan dari kode asal

Password `password_hash`, sesi aman, CSRF di setiap POST dan API scanner, rate limit login, akses deny-by-default yang dicek di server, check-in atomik, PDO prepared statements, output di-escape, ekspor CSV aman dari formula injection, audit log.

## Pustaka pihak ketiga

- [html5-qrcode](https://github.com/mebjas/html5-qrcode) 2.3.8 (Apache-2.0): scanner kamera
- [qrcodejs](https://github.com/davidshimjs/qrcodejs) 1.0.0 (MIT): gambar QR
- [TCPDF](https://github.com/tecnickcom/TCPDF) 6.11.4 (LGPL-3.0-or-later): PDF kartu QR
