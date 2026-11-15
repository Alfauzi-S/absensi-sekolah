# Baseline kecepatan scan: smartcity-attendance (sebelum diubah)

Diukur 15 November 2026 pada kode asal (commit `Import smartcity-attendance as base`), sebagai pembanding untuk target PRD (tanda hijau p95 ≤ 300 ms dengan scanner USB).

## Cara ukur

- Server: `php -S` lokal + SQLite, data demo bawaan (event 1, 50 peserta berstatus "belum"), login sebagai Admin Presensi.
- Alat: Playwright (Chromium headless) di mesin yang sama, jadi **tanpa latensi jaringan**. Di sekolah dengan Wi-Fi, tiap round-trip bertambah 20–150 ms.
- Skrip: `tests/perf/baseline-old-flow.js` (dijalankan terhadap repo lama; dipertahankan untuk dibandingkan ulang nanti).
- 25 scan per skenario, kode QR dimasukkan lewat kolom "Input manual" (meniru scanner USB keyboard-wedge). Waktu decode kamera tidak diukur; html5-qrcode berjalan 10 fps sehingga decode saja minimal ~100 ms.

## Hasil

| Tahap alur lama | p50 | p95 | maks |
| --- | --- | --- | --- |
| API `POST /api/scan/validate` | 2 ms | 3 ms | 8 ms |
| API `POST /api/scan/save` | 3 ms | 4 ms | 4 ms |
| API validate + save (2 round-trip) | 5 ms | 6 ms | 12 ms |
| UI: kode masuk → layar "QR valid, periksa identitas" | 33 ms | 42 ms | 42 ms |
| UI: klik "Simpan Kehadiran" → "Kehadiran tersimpan" | 59 ms | 69 ms | 71 ms |
| UI: klik "Scan Berikutnya" → siap memindai | 40 ms | 44 ms | 46 ms |
| **UI total tanpa waktu manusia** | **130 ms** | **145 ms** | 145 ms |

## Kesimpulan untuk desain baru

1. Server sudah cepat (≈5 ms untuk dua request). Bottleneck alur lama bukan di database, tetapi di **jumlah langkah**: 2 request + 2 klik manusia (Simpan, Scan Berikutnya) per siswa. Dengan waktu reaksi manusia ±1 detik per klik, satu siswa realistis memakan 2–3 detik.
2. Alur baru memakai **satu request** (`POST /api/scan`) tanpa klik, dan tanda di layar diambil dari roster lokal, sehingga total per siswa ditargetkan ≤ 300 ms dan tidak bergantung pada round-trip.
3. Angka UI di atas (130 ms) adalah batas bawah yang harus dikalahkan alur baru **termasuk** waktu round-trip nyata, bukan hanya di localhost.
