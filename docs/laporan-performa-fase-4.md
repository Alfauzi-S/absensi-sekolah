# Laporan uji performa Fase 4

Tanggal uji: 16 November 2026. Dijalankan dengan `bash tests/perf/run.sh` (hasil mentah JSON di `storage/perf/`).

**Lingkungan uji** (bukan server sekolah): kontainer Linux 4 inti, PHP 8.3 server bawaan (`php -S`) dengan 8 worker, SQLite mode WAL, tanpa OPcache dan tanpa APCu, klien dan server di mesin yang sama. Kiosk dijalankan di Chromium lewat Playwright, kartu "di-scan" dengan ketikan 12 karakter + Enter seperti scanner USB. Data: data demo (30 kelas, 1.080 siswa) ditambah satu kelas uji 500 siswa untuk kiosk dan 6.000 siswa + 30 perangkat untuk uji beban.

Angka di server sekolah (MariaDB, PHP-FPM + OPcache, jaringan Wi-Fi) akan berbeda. Yang tidak berubah: tanda di layar berasal dari roster lokal, jadi tidak bergantung pada jaringan maupun server.

## Ringkasan

| Uji (PRD Fase 4) | Target | Hasil | Status |
| --- | --- | --- | --- |
| Latensi scanner USB, 1.000 scan | tanda p95 ≤ 300 ms, p99 ≤ 500 ms | tanda p50 < 1 ms, p95 < 1 ms, p99 1 ms; sampai tersimpan p50 12 ms, p95 16 ms, p99 20 ms, maks 52 ms | Lulus |
| 36 scan beruntun | ≤ 60 detik, nol hilang | 1,2 detik sampai semua tersimpan, 36 dari 36 | Lulus |
| Jaringan lambat (RTT 300 ms) | tanda ≤ 300 ms dari roster lokal, sinkron ≤ 5 detik | tanda p95 1 ms, maks 26 ms; tersimpan p95 354 ms; sinkron terlama 0,4 detik | Lulus |
| Offline 500 scan, halaman dimuat ulang di tengah, lalu tersambung | 500 tersinkron, nol ganda, waktu scan = waktu scan | 250 scan bertahan melewati muat ulang, 500 dari 500 tercatat, 0 ganda, 500 waktu scan berada di jendela offline, sinkron 1,0 detik, 0 ditolak | Lulus |
| Batch yang sama dikirim 3 kali | 1 catatan per scan | 50 scan → 50 baris kehadiran, 50 log | Lulus |
| Beban 20 scan/detik, 30 perangkat | t_server p95 ≤ 50 ms, 0% error | p50 2,5 ms, p95 3,8 ms, p99 5,5 ms, 0% error (60 detik, bukan 10 menit) | Lulus, durasi dipendekkan |
| Stres sampai 100 scan/detik | p95 ≤ 50 ms, error < 0,1% | p95 3,0 ms, p99 4,7 ms, 0% error | Lulus |
| Titik gagal | dicatat | belum tercapai sampai 400 scan/detik (lihat catatan) | Dicatat |
| Ukuran JS kiosk | ≤ 150 KB gzip | kiosk.js 10 KB gzip (ditambah html5-qrcode hanya bila kamera dipakai) | Lulus |
| Endurance 30 scan/detik 2 jam | stabil | tidak dijalankan di lingkungan ini | Belum |
| Kamera, 300 scan | p95 ≤ 800 ms | perlu tablet/HP sungguhan | Belum |
| Lighthouse kiosk | skor ≥ 90, siap ≤ 2 detik | Lighthouse tidak tersedia di lingkungan ini | Belum |
| Slow query log MariaDB | tidak ada query lambat | uji memakai SQLite; perlu dijalankan di server MariaDB | Belum |

## Uji beban `POST /api/scan`

Laju tetap (open loop) dari 30 perangkat virtual, koneksi keep-alive, `node tests/perf/load.js`.

| Laju (scan/detik) | Durasi | Terkirim | Error | t_server p50 | p95 | p99 | Bolak-balik p95 | Jalur |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 20 | 60 dtk | 1.200 | 0% | 2,5 ms | 3,8 ms | 5,5 ms | 9,4 ms | scan baru |
| 50 | 30 dtk | 1.500 | 0% | 2,2 ms | 3,4 ms | 5,0 ms | 8,4 ms | scan baru |
| 100 | 30 dtk | 3.000 | 0% | 2,0 ms | 3,0 ms | 4,7 ms | 7,1 ms | scan baru |
| 200 | 20 dtk | 4.000 | 0% | 0,6 ms | 1,9 ms | 4,2 ms | 7,3 ms | 250 baru, 3.750 duplikat |
| 400 | 15 dtk | 6.000 | 0% | 0,6 ms | 4,1 ms | 19,3 ms | 12,7 ms | duplikat |

Catatan jujur: 6.000 kartu uji habis di tengah tahap 200 scan/detik, jadi tahap 200 dan 400 sebagian besar mengukur jalur "sudah tercatat" yang lebih ringan (tanpa INSERT kehadiran). Titik gagal belum ditemukan; yang terukur adalah bahwa sampai 100 scan/detik scan baru (5 kali target PRD) server masih jauh di bawah 50 ms.

## Yang membuatnya cepat

- **Roster lokal**: kiosk mengunduh kode kartu, nama, dan sesi ruangannya (`GET /api/roster`), lalu memutuskan Hadir/Terlambat/Sudah tercatat/Bukan kelas ini di perangkat. Tanda muncul sebelum server menjawab. Jawaban server tetap yang menentukan: bila berbeda, layar dan hitungan dikoreksi.
- **Antrean offline** di IndexedDB dengan `scan_id` unik. Scan dikirim satu per satu saat online, dan per 50 lewat `POST /api/scan/batch` setelah offline. Waktu scan memakai jam perangkat yang dikoreksi dengan selisih jam terhadap server.
- **Server**: tanpa sesi PHP untuk request bertoken perangkat, satu query gabungan untuk kartu + siswa + kelas, sesi aktif per ruangan di-cache per menit (APCu bila terpasang), log scan ditulis setelah jawaban dikirim.
- **Service worker** menyimpan halaman kiosk dan asetnya, sehingga kiosk tetap bisa dibuka ulang saat jaringan putus.

## Yang masih harus diuji di sekolah (Fase 7)

1. Kamera tablet/HP Android: 300 scan, target p95 ≤ 800 ms.
2. Endurance 2 jam dan beban 10 menit penuh di server sungguhan (MariaDB + PHP-FPM), termasuk slow query log.
3. Lighthouse di tablet kiosk.
4. Tablet benar-benar dimatikan saat offline (di sini disimulasikan dengan memuat ulang halaman saat jaringan putus).
