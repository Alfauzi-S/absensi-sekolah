# Panduan Pilot dan Go-live

Panduan ini adalah rencana kerja Fase 7 (PRD bagian 7 dan 8): pilot 2 kelas selama 1 minggu, perbaikan dan pelatihan, lalu rollout ke semua kelas dengan cadangan harian dan pemantauan minggu pertama. Setiap langkah menyebut halaman aplikasi yang dipakai dan kriteria lulusnya.

## Ringkasan jadwal

| Minggu | Kegiatan | Penanggung jawab | Selesai bila |
| --- | --- | --- | --- |
| H-7 s.d. H-1 | Persiapan server, data, kartu, perangkat dua kelas pilot | ALF (server), TU (data) | Daftar periksa persiapan semua tercentang |
| Minggu 12 (5 hari sekolah) | Pilot 2 kelas | Wali kelas pilot, TU, ALF | Kecepatan dan akurasi lulus (lihat di bawah) |
| Akhir minggu 12 | Perbaikan dari pilot, pelatihan TU dan guru, UAT per peran | ALF, TU, kepala sekolah | Semua skenario UAT lulus dan ditandatangani |
| Minggu 13 | Rollout ke semua kelas, pemantauan harian | ALF, TU | Minggu pertama tanpa masalah terbuka |

## 1. Persiapan (sebelum pilot)

### Server

1. Jalankan aplikasi dengan Docker: `cp .env.example .env`, isi `DB_PASSWORD` dan `DB_ROOT_PASSWORD`, lalu `docker compose up -d --build`. Service `scheduler` ikut berjalan (tutup sesi tiap 5 menit, kirim WhatsApp, cadangan harian pukul 01:30).
2. Pasang HTTPS di depan aplikasi (Caddy/Nginx/Cloudflare). Kamera tablet hanya bisa dipakai lewat HTTPS; scanner USB tetap jalan tanpa HTTPS.
3. Buka `/install`, buat Super Admin. Jangan centang data demo di server produksi.
4. Buka **Kesehatan Sistem** (`/admin/kesehatan`): semua kartu harus hijau, kecuali "Perangkat scanner" (belum ada perangkat) dan "Cadangan harian" (tekan **Buat cadangan sekarang**).
5. Daftarkan alamat `https://<domain>/healthz` ke pemantau uptime gratis (UptimeRobot, Better Stack, dsb.). Jawaban 200 berarti aplikasi dan database hidup.
6. Atur salinan cadangan ke luar server, misalnya cron harian `rsync storage/backups/` ke disk lain atau `rclone copy storage/backups remote:absensi` ke Google Drive. Cadangan yang hanya ada di server yang sama tidak menolong bila disk server rusak.

### Data sekolah (TU)

1. **Pengaturan**: nama sekolah, NPSN, alamat, kepala sekolah, mode absensi (bawaan "Masuk dan pulang"), jam masuk/pulang, toleransi terlambat, hari libur mingguan.
2. **Tahun Ajaran** dan semester aktif; **Hari Libur** semester ini.
3. **Impor Data**: siswa (template Excel) dan jadwal bila memakai mode per jam pelajaran. Periksa laporan baris gagal.
4. **Kelas**: wali kelas sudah terisi untuk semua kelas (wali kelas yang mengisi izin dan koreksi).
5. **Akun Pengguna**: akun guru, TU, kepala sekolah. Bagikan password sementara; pengguna wajib menggantinya saat login pertama.

### Dua kelas pilot

1. Pilih dua kelas dengan wali kelas yang bersedia mencatat absen manual 3 hari pertama. Simpan di **Pilot dan Go-live** (kelas + tanggal mulai/selesai).
2. **Kartu QR**: terbitkan dan cetak kartu kedua kelas (PDF A4, 10 kartu per halaman, ukuran KTP). Laminasi bila perlu.
3. **Perangkat Scanner**: buat satu perangkat per ruangan, pasangkan tablet/laptop dengan kode 6 digit, sambungkan scanner USB, buka mode layar penuh. Uji satu kartu.
4. Untuk wali kelas pilot: tunjukkan cara membuka sesi live, mencatat izin, mengoreksi status, dan mengisi absen manual di halaman Banding.

## 2. Pilot (5 hari sekolah)

| Hari | Yang dilakukan |
| --- | --- |
| Setiap pagi | ALF/TU membuka **Kesehatan Sistem**: penjadwal < 15 menit, cadangan < 26 jam, perangkat dua kelas aktif. |
| Hari 1–3 | Wali kelas tetap mengabsen di kertas seperti biasa, lalu mengisi kertas itu di **Pilot → Isi absen manual hari ini** (atau impor CSV). Setiap selisih diberi penjelasan. |
| Setiap hari | Guru mengirim masukan lewat menu **Kirim Masukan** (scanner, kartu, layar, rekap). |
| Hari 5 | Unduh **Laporan pilot (Excel)**: sheet Kecepatan, Akurasi, Masukan. |

Kriteria lulus pilot (PRD bagian 8, Fase 7):

| Uji | Cara | Lulus bila |
| --- | --- | --- |
| Kecepatan nyata | Halaman Pilot, kecepatan per kelas dari metrik kiosk selama 1 minggu | p95 "kode → hasil tampil" ≤ 300 ms (scanner USB) di kedua kelas, minimal 20 scan |
| Akurasi | Banding absen manual guru 3 hari pertama | Selisih 0, atau setiap selisih punya penjelasan |
| UAT per peran | [Skenario UAT](uat-skenario.md) | Semua skenario lulus dan ditandatangani sekolah |
| Pemulihan | `bash tests/ops/restore-drill.sh` atau langkah manual di bagian 5 | Sistem jalan lagi < 30 menit |

Bila kecepatan tidak lulus: cek halaman **Kecepatan Scan** per perangkat. "Tanda pertama di layar" lambat berarti tablet lemah; "bolak-balik server" lambat berarti jaringan sekolah ke server (roster lokal tetap membuat tanda pertama cepat). Bila akurasi tidak lulus: baca penjelasan selisih; pola yang sering adalah siswa lupa scan (perlu pembiasaan) atau guru mencatat terlambat sebagai hadir (beda aturan, bukan kesalahan sistem).

## 3. Perbaikan, pelatihan, dan UAT

1. Tindak lanjuti masukan di halaman Pilot (tandai selesai dengan catatan tindak lanjut).
2. Pelatihan 60 menit per kelompok, memakai [Buku Panduan](Manual-Book-Absensi-Sekolah.pdf):
   - TU: data master, impor, kartu, perangkat, izin, rekap dan ekspor, cadangan.
   - Guru dan wali kelas: sesi live, tutup sesi, jam kosong, tandai hadir manual, izin, koreksi, rekap kelas.
   - Kepala sekolah: dashboard, rekap sekolah, laporan pilot.
3. Jalankan [skenario UAT](uat-skenario.md) bersama perwakilan tiap peran; tanda tangani lembar pengesahan.

## 4. Rollout ke semua kelas

1. Cetak dan bagikan kartu QR semua kelas (TU, per kelas dari halaman Kartu QR).
2. Pasangkan perangkat semua ruangan; uji satu kartu di setiap ruangan.
3. Kosongkan pengaturan pilot (atau biarkan sebagai arsip).
4. Umumkan ke siswa: kartu dibawa setiap hari, scan saat masuk (dan pulang bila mode masuk/pulang).
5. Nyalakan notifikasi WhatsApp dan portal orang tua bila sekolah sudah siap (opsional, lihat Buku Panduan bab 8). Mulai dengan provider Sandbox satu hari untuk melihat isi pesan.

## 5. Pemantauan minggu pertama

Setiap pagi (5 menit):

- **Kesehatan Sistem**: semua hijau. Kuning/merah: baca keterangan di kartunya.
- **Dashboard**: kelas dengan kehadiran terendah; kelas 0% biasanya berarti scanner ruangan itu mati.
- **Sesi Absensi**: tidak ada sesi kemarin yang masih terbuka.
- **Notifikasi WA** (bila dinyalakan): tidak ada yang Gagal.

Setiap minggu: unduh satu cadangan dari Kesehatan Sistem dan simpan di tempat lain.

## 6. Cadangan dan pemulihan

- Otomatis: tiap malam (pukul `backup_time`, bawaan 01:30) ke `storage/backups/absensi-YYYYMMDD-HHMMSS.zip`, 14 terbaru disimpan. Isinya: semua tabel (format netral, bisa pindah antara SQLite dan MariaDB), foto siswa, lampiran surat izin.
- Manual: tombol **Buat cadangan sekarang** di Kesehatan Sistem, atau `php bin/backup.php` (`docker compose exec app php bin/backup.php`).
- Pindah server / pemulihan:
  1. Siapkan server baru dengan langkah Persiapan → Server nomor 1–2 (tanpa membuka `/install`).
  2. Salin file cadangan ke `storage/backups/` server baru.
  3. `docker compose exec app php bin/restore.php storage/backups/<file>.zip` untuk melihat isinya, lalu ulangi dengan `--yes`.
  4. Buka aplikasi, login, cek Kesehatan Sistem. Arahkan DNS ke server baru; perangkat scanner perlu dipasangkan ulang hanya bila alamat domain berubah.
- Latihan pemulihan otomatis: `bash tests/ops/restore-drill.sh` (SQLite → SQLite) atau dengan `DRILL_TARGET=mysql ...` (SQLite → MariaDB). Hasil latihan di server pengembangan: 321.227 baris (1 semester, 30 kelas) dipulihkan ke MariaDB dan aplikasi menjawab login dalam **9,3 detik**; target < 30 menit.

## 7. Rencana mundur

Bila aplikasi tidak bisa dipakai pada hari sekolah (server mati, jaringan putus total):

1. Kiosk tetap menerima scan dan menyimpannya di antrean offline perangkat; scan dikirim otomatis saat jaringan pulih (jam scan asli tetap dipakai).
2. Bila tablet juga mati: guru mengabsen di kertas, lalu wali kelas memasukkan lewat **Sesi → Tandai hadir manual** atau koreksi status dengan alasan "Absen kertas, server mati".
3. Bila server rusak: pulihkan cadangan terakhir ke server baru (bagian 6). Data sejak cadangan terakhir diisi ulang dari antrean offline perangkat dan absen kertas.
