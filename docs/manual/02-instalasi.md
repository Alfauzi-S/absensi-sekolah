# Instalasi dan Server

Bab ini untuk pengelola server. Pengguna lain bisa langsung ke Bab 3.

## Kebutuhan

- **Server** Linux dengan Docker dan Docker Compose, minimal 2 vCPU dan 2 GB RAM untuk sekitar 1.000 siswa.
- **Domain dan HTTPS.** Kamera tablet hanya bisa dipakai lewat HTTPS. Scanner USB tetap berjalan tanpa HTTPS.
- **Perangkat kiosk**: tablet Android atau laptop dengan browser Chrome/Edge versi terbaru, dan scanner QR USB (disarankan) per ruangan.
- Tanpa Docker juga bisa: PHP 8.1+ dengan ekstensi pdo_mysql/pdo_sqlite, mbstring, gd, zip, serta MySQL/MariaDB.

## Memasang dengan Docker

1. Ambil kode aplikasi lalu salin berkas lingkungan:

   ```bash
   cp .env.example .env      # isi DB_PASSWORD dan DB_ROOT_PASSWORD
   docker compose up -d --build
   ```

2. Docker menjalankan empat layanan: **web** (Nginx), **app** (PHP-FPM), **db** (MariaDB), dan **scheduler** (tugas berkala tiap 5 menit).
3. Buka `http://<server>:8090/install`, isi nama sekolah dan akun Super Admin. Jangan centang **data demo** di server sekolah.
4. Pasang HTTPS di depan port 8090 (Caddy, Nginx, atau Cloudflare Tunnel).

## Tugas berkala (scheduler)

Layanan `scheduler` menjalankan `bin/close-sessions.php` setiap 5 menit. Tugasnya:

- **Menutup sesi** yang sudah lewat dan menandai Alpa siswa yang tidak scan.
- **Mengirim notifikasi WhatsApp** yang mengantre (bila dinyalakan).
- **Membersihkan** data sementara yang kedaluwarsa.
- **Membuat cadangan harian** sekali sehari setelah pukul 01:30, dan menyimpan 14 cadangan terakhir.

Tanpa Docker, pasang cron:

```bash
*/5 * * * * php /path/aplikasi/bin/close-sessions.php
```

## Cadangan dan pemulihan

- **Lokasi**: `storage/backups/absensi-YYYYMMDD-HHMMSS.zip`. Isinya data semua tabel, foto siswa, dan lampiran surat izin.
- **Cadangan manual**: tombol **Buat cadangan sekarang** di halaman Kesehatan Sistem, atau `php bin/backup.php`.
- **Salin ke luar server**: atur `rsync` atau `rclone` harian ke disk lain atau Google Drive. Cadangan di disk yang sama tidak menolong bila disk rusak.
- **Memulihkan ke server baru**: pasang aplikasi seperti di atas, salin berkas zip, lalu jalankan:

  ```bash
  php bin/restore.php storage/backups/absensi-20261117-013000.zip
  ```

  Pemulihan menghapus isi database tujuan, memuat ulang semua data, dan mencocokkan jumlah baris tiap tabel. Cadangan dari SQLite bisa dipulihkan ke MySQL dan sebaliknya. Uji pulih dengan sekitar 320 ribu baris selesai dalam kurang dari 1 menit.

## Pembaruan aplikasi

1. Buat cadangan manual lebih dulu.
2. Ambil kode terbaru, lalu `docker compose up -d --build`.
3. Jalankan migrasi database: `docker compose exec app php bin/migrate.php` (tanpa Docker: `php bin/migrate.php`). Super Admin juga melihat tombol **Jalankan migrasi** di dashboard bila ada migrasi yang belum dijalankan.
4. Buka Kesehatan Sistem dan pastikan semua kartu hijau.
