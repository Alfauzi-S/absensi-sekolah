# Lampiran Teknis

## Teknologi

- **Server**: PHP 8.1+ tanpa framework, MySQL/MariaDB (produksi) atau SQLite (uji).
- **Kiosk**: halaman web biasa dengan service worker dan IndexedDB untuk antrean offline.
- **PDF kartu**: TCPDF. **Excel**: penulis XLSX bawaan aplikasi.

## Perintah server

| Perintah | Fungsi |
| --- | --- |
| `php bin/migrate.php` | Menjalankan migrasi database yang belum dijalankan |
| `php bin/close-sessions.php` | Tugas berkala: tutup sesi, kirim WhatsApp, bersih-bersih, cadangan harian |
| `php bin/scheduler.php` | Menjalankan tugas berkala tiap 5 menit tanpa cron (dipakai Docker) |
| `php bin/backup.php` | Membuat cadangan sekarang; `--list` menampilkan daftar cadangan |
| `php bin/restore.php <zip>` | Memulihkan cadangan ke database yang dikonfigurasi |

## Endpoint kiosk

| Endpoint | Fungsi |
| --- | --- |
| `POST /api/device/pair` | Menukar kode pasangan 6 digit dengan token perangkat |
| `GET /api/device/me` | Identitas perangkat, pengaturan, dan sesi yang sedang berlaku |
| `GET /api/roster` | Daftar kartu dan sesi hari ini untuk tanda instan dan mode offline |
| `POST /api/scan` | Mencatat satu scan (idempoten dengan `scan_id`) |
| `POST /api/scan/batch` | Mengirim antrean offline |
| `GET /healthz` | Status aplikasi dan database untuk pemantau uptime |

## Pengujian

| Perintah | Isi |
| --- | --- |
| `phpunit` | Tes unit: akses per peran, impor, kartu, sesi, rekap, notifikasi, portal, cadangan, pilot |
| `bash tests/smoke.sh` | Instal dengan data demo, buka semua halaman untuk setiap peran |
| `bash tests/e2e/run.sh` | Kiosk 36 siswa lewat scanner USB (Playwright) |
| `bash tests/ops/restore-drill.sh` | Cadangkan server lama, pulihkan ke server baru, ukur waktunya |

## Membuat ulang buku panduan

Lihat `docs/manual/README.md`.
