# Absensi Sekolah (QR)

Aplikasi absensi siswa dengan kartu QR, dibangun ulang dengan **Laravel 13** (PHP 8.3+) dan **MariaDB**. Versi ini sengaja dibuat sederhana: hanya fitur yang dipakai sehari-hari, tampilan baru yang ramah HP.

## Fitur

| Menu | Isi |
| --- | --- |
| **Beranda** | Kehadiran hari ini (persen, hadir, terlambat, izin/sakit, alpa, belum tercatat), per kelas, scan terbaru (diperbarui otomatis), tren 10 hari sekolah, siswa yang sering alpa |
| **Kiosk** | Layar penuh untuk tablet/HP di gerbang. Scan kartu QR dengan kamera atau scanner USB. Umpan balik warna besar + bunyi. Dibuka dengan tautan rahasia dari Pengaturan (tanpa login) |
| **Absensi Harian** | Daftar hadir per kelas per tanggal (jam masuk, jam pulang, terlambat berapa menit). Wali kelas/TU mengubah status dengan sekali klik (H T S I D A), tersimpan otomatis |
| **Absensi per Jam** | Guru membuka sesi pelajaran (kelas, mapel, jam ke). Daftar terisi Hadir, siswa yang sakit/izin/alpa hari itu ikut statusnya; guru mengubah yang tidak hadir. Bisa juga memindai kartu siswa dengan kamera HP guru |
| **Izin & Sakit** | Surat izin/sakit/dispensasi untuk rentang tanggal (maks. 60 hari) dengan lampiran foto/PDF. Langsung mengubah status, termasuk hari yang sudah lewat. Dihapus = status kembali |
| **Rekap** | Rekap bulanan per kelas (kode per tanggal, libur diarsir, jumlah, % hadir) dan unduh **Excel** siap cetak dengan tanda tangan wali kelas dan kepala sekolah. Ringkasan sekolah per kelas untuk rentang tanggal. Riwayat per siswa. Rekap per jam pelajaran + Excel |
| **Siswa, Kelas, Kartu QR, Impor** | Data siswa dan kelas, pindah/naik kelas atau tandai lulus massal, cetak kartu QR ukuran kartu ATM (8,6 × 5,4 cm) per kelas, impor Excel/CSV (NIS yang ada diperbarui, kelas baru dibuat otomatis) |
| **Pengguna, Pengaturan** | Akun dan peran. Nama sekolah, kepala sekolah, jam absensi, libur mingguan, hari libur, tautan kiosk, mode absensi per jam, mapel, jam pelajaran |

### Peran

| Peran | Boleh |
| --- | --- |
| Admin | Semua, termasuk pengguna dan pengaturan |
| Tata Usaha | Data siswa, kelas, kartu, impor, izin, koreksi absensi semua kelas |
| Kepala Sekolah | Melihat semua (dashboard, absensi, rekap, ringkasan). Tidak mengubah data |
| Guru | Melihat dan mengoreksi kelas yang diwalikan, mencatat izin siswanya. Absensi per jam sesuai Pengaturan (semua guru di semua kelas, atau hanya wali kelas) |

### Aturan absensi harian

- Scan masuk dibuka pada **jam mulai** (bawaan 06:00). Lewat **batas terlambat** (07:10) statusnya Terlambat dengan jumlah menit. Scan masuk masih diterima sampai **jam pulang** (14:00); setelah itu scan dihitung pulang sampai **akhir scan pulang** (17:00).
- Pada jam pulang, siswa yang belum tercatat otomatis diberi **Alpa**, atau status surat izinnya (dijalankan service `scheduler` tiap 5 menit). Siswa yang tidak scan masuk tetapi scan pulang dicatat Terlambat ("Tidak scan masuk").
- Prioritas: **koreksi manual > surat izin > scan > Alpa otomatis**. Jam scan tetap disimpan walau statusnya kalah prioritas.
- Akhir pekan dan hari libur tidak dihitung. % hadir = (Hadir + Terlambat) ÷ hari efektif.

## Menjalankan dengan Docker (port 8090)

```bash
cp .env.example .env      # isi DB_PASSWORD dan DB_ROOT_PASSWORD
docker compose up -d --build
```

Buka `http://<server>:8090`. Pada instalasi baru, halaman pertama meminta nama sekolah dan akun admin.

Memperbarui: `git pull && docker compose up -d --build`. Saat container `app` start, migrasi database berjalan otomatis.

Service: `web` (Nginx, satu-satunya yang membuka port), `app` (PHP-FPM), `scheduler` (`php artisan schedule:work`), `db` (MariaDB). Data database di volume `absensi_sekolah_db`, file unggahan dan kunci aplikasi di volume `absensi_sekolah_storage`.

### Pindah dari versi lama

Versi Laravel memakai database dan file `.env` yang sama dengan versi lama, jadi cukup `git pull && docker compose up -d --build` di folder yang sama. Pada start pertama, data versi lama dipindahkan otomatis sekali:

- pengaturan sekolah dan jam (batas terlambat = jam masuk + toleransi lama),
- semua akun dengan **password yang sama** (Super Admin menjadi Admin),
- kelas tahun ajaran aktif dan wali kelasnya, semua siswa beserta **kode kartu QR aktif** (kartu yang sudah dicetak tetap berlaku),
- hari libur, surat izin yang masih berlaku, dan seluruh kehadiran harian Masuk/Pulang.

Tabel versi baru memakai awalan `abs_`, sehingga tabel lama tidak diubah dan masih bisa dipakai bila ingin kembali ke versi lama (`git checkout <commit lama>`). Data yang tidak dipindahkan: absensi per jam versi lama, jadwal, perangkat kiosk, notifikasi WhatsApp, portal orang tua, masukan, dan data pilot.

Perintah lain:

```bash
docker compose exec app php artisan absensi:admin admin --password=PasswordBaru123   # buat/reset akun admin
docker compose exec app php artisan absensi:tandai-alpa --date=2026-11-17            # beri Alpa untuk tanggal tertentu
docker compose exec app php artisan absensi:impor-lama                               # pindah data lama (bila aplikasi baru masih kosong)
```

## Pengembangan

```bash
composer install
cp .env.example .env && php artisan key:generate
# untuk lokal: DB_CONNECTION=sqlite di .env
php artisan migrate
php artisan db:seed --class=DemoSeeder   # data contoh, password semua akun: password123
php artisan serve
php artisan test
```

Tanpa build tool front-end: CSS di `public/css/app.css`, JavaScript di `public/js/`. Pemindai QR memakai html5-qrcode, kartu QR memakai qrcodejs (keduanya di `public/vendor`).
