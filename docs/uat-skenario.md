# Skenario UAT per Peran

Uji penerimaan pengguna (PRD bagian 8, Fase 7): setiap skenario dijalankan oleh perwakilan peran di server sekolah dengan data asli (atau data demo bila belum ada). Tulis **L** (lulus) atau **G** (gagal) dan catatan bila gagal. Lulus bila semua skenario L dan lembar pengesahan ditandatangani.

Tanggal uji: ____________________  Server/alamat: ____________________  Versi aplikasi: ____________________

## A. Tata Usaha (TU)

| No | Skenario | Langkah | Hasil yang diharapkan | L/G | Catatan |
| --- | --- | --- | --- | --- | --- |
| A1 | Masuk dan ganti password | Login dengan password sementara | Diminta membuat password baru; setelah itu masuk ke Dashboard | | |
| A2 | Impor siswa | Impor Data → unduh template → isi 5 siswa (1 baris sengaja salah) → unggah | 4 siswa masuk ke kelasnya; baris salah dilaporkan dengan nomor baris dan alasannya | | |
| A3 | Ubah data siswa | Siswa → pilih siswa → Ubah → ganti nomor HP orang tua | Tersimpan; tercatat di Audit Log | | |
| A4 | Terbitkan dan cetak kartu | Kartu QR → pilih kelas → Terbitkan → Cetak PDF | PDF A4 berisi 10 kartu per halaman dengan garis potong; QR terbaca scanner | | |
| A5 | Ganti kartu hilang | Siswa → Ganti kartu (alasan Hilang) | Kartu lama ditolak scanner ("Kartu tidak berlaku"), kartu baru diterima | | |
| A6 | Catat izin/sakit | Izin dan Sakit → Catat → siswa, Sakit, 2 hari, lampiran foto surat | Status siswa di sesi tanggal itu menjadi Sakit, termasuk sesi yang sudah lewat | | |
| A7 | Batalkan izin | Izin dan Sakit → Batalkan (dengan alasan) | Status kembali ke hasil scan atau Alpa | | |
| A8 | Rekap bulanan kelas | Rekap → kelas → bulan ini → Excel | File Excel berkop sekolah, kode H/T/S/I/A/D per tanggal, libur diarsir, % hadir, tanda tangan | | |
| A9 | Rekap semester sekolah | Rekap → Sekolah → Semester → Excel | Satu file, satu sheet per kelas + ringkasan; selesai < 30 detik | | |
| A10 | Pasangkan perangkat | (bersama Super Admin) Perangkat Scanner → kode 6 digit → masukkan di tablet | Tablet menampilkan "Siap memindai" dan nama ruangan | | |
| A11 | Kode portal orang tua | Kelas → Kode portal (CSV) | CSV berisi NIS, nama, kode akses; kode hanya muncul sekali | | |

## B. Guru mata pelajaran

| No | Skenario | Langkah | Hasil yang diharapkan | L/G | Catatan |
| --- | --- | --- | --- | --- | --- |
| B1 | Lihat sesi saya | Sesi Absensi → hari ini | Hanya sesi kelas yang diajar | | |
| B2 | Pantau scan live | Buka sesi berjalan saat siswa scan | Nama siswa muncul dalam ≤ 5 detik dengan jam scan | | |
| B3 | Tandai hadir manual | Sesi → siswa tanpa kartu → Tandai hadir | Status Hadir, metode "manual", tercatat siapa yang menandai | | |
| B4 | Jam kosong | Sesi → Batalkan (jam kosong) | Tidak ada Alpa untuk sesi itu; tidak dihitung di rekap | | |
| B5 | Tutup sesi | Sesi → Tutup | Siswa yang belum scan menjadi Alpa; yang punya izin mendapat status izinnya | | |
| B6 | Batas akses | Buka alamat kelas lain yang tidak diajar | Ditolak (403) | | |

## C. Wali kelas

| No | Skenario | Langkah | Hasil yang diharapkan | L/G | Catatan |
| --- | --- | --- | --- | --- | --- |
| C1 | Koreksi status | Sesi → siswa → Koreksi → Hadir, alasan "Kartu tertinggal, ada di kelas" | Status berubah; riwayat perubahan menampilkan status lama, baru, alasan, nama wali kelas | | |
| C2 | Koreksi tanpa alasan | Koreksi tanpa mengisi alasan | Ditolak, status tidak berubah | | |
| C3 | Izin kelas sendiri | Izin dan Sakit → Catat untuk siswa kelasnya | Tersimpan; untuk kelas lain tidak bisa | | |
| C4 | Rekap kelas | Rekap → kelas sendiri → Bulanan/Harian/Semester | Angka sama dengan hitungan manual wali kelas | | |
| C5 | Riwayat siswa | Rekap → klik nama siswa | Per hari: status, jam scan, menit terlambat, keterangan surat/koreksi | | |
| C6 | Banding absen manual (pilot) | Pilot → Isi absen manual → isi sesuai kertas → simpan | Selisih ditandai kuning; setiap selisih bisa diberi penjelasan | | |

## D. Kepala sekolah

| No | Skenario | Langkah | Hasil yang diharapkan | L/G | Catatan |
| --- | --- | --- | --- | --- | --- |
| D1 | Dashboard | Login → Dashboard | Hadir/izin/alpa hari ini, % hadir bulan ini, kelas terendah, siswa alpa ≥ 3 hari | | |
| D2 | Rekap sekolah | Rekap → Sekolah → Excel | Ringkasan per kelas, bisa diunduh | | |
| D3 | Hanya lihat | Coba buka Siswa → Tambah, Kartu QR, Akun Pengguna | Ditolak (403); menu tidak ditampilkan | | |
| D4 | Laporan pilot | Pilot dan Go-live → Laporan pilot (Excel) | Sheet Kecepatan, Akurasi, Masukan | | |

## E. Super Admin

| No | Skenario | Langkah | Hasil yang diharapkan | L/G | Catatan |
| --- | --- | --- | --- | --- | --- |
| E1 | Akun pengguna | Akun Pengguna → buat akun guru → reset password | Password sementara ditampilkan sekali; guru wajib menggantinya | | |
| E2 | Pengaturan sekolah | Pengaturan → ubah toleransi terlambat 10 → 15 menit | Scan menit ke-14 setelah jam masuk menjadi Hadir mulai hari berikutnya | | |
| E3 | Kesehatan sistem | Kesehatan Sistem | Semua pemeriksaan hijau; keterangan jelas bila kuning/merah | | |
| E4 | Cadangan | Kesehatan Sistem → Buat cadangan sekarang → Unduh | File .zip tersimpan dan bisa diunduh; tercatat di Audit Log | | |
| E5 | Pemulihan | Pulihkan cadangan ke server uji (`php bin/restore.php <file> --yes`) | Aplikasi jalan lagi < 30 menit, jumlah baris cocok | | |
| E6 | Notifikasi WhatsApp (opsional) | Pengaturan → Alpa dan Terlambat, provider Sandbox → Notifikasi WA → Kirim uji | Pesan uji tercatat; satu pesan per siswa per hari | | |
| E7 | Audit log | Audit Log → filter "Akses ditolak" | Percobaan akses B6/D3 tercatat | | |

## F. Siswa dan scanner (dijalankan TU/guru di ruang kelas)

| No | Skenario | Langkah | Hasil yang diharapkan | L/G | Catatan |
| --- | --- | --- | --- | --- | --- |
| F1 | Scan tepat waktu | Siswa menempelkan kartu ke scanner sebelum batas terlambat | Layar hijau "Hadir" dengan nama dan foto, bunyi pendek, siap untuk siswa berikutnya | | |
| F2 | Scan terlambat | Scan setelah toleransi | Layar kuning "Terlambat N menit" | | |
| F3 | Scan dua kali | Scan kartu yang sama lagi | "Sudah tercatat HH:MM", tidak tercatat ganda | | |
| F4 | Salah kelas | Kartu siswa kelas lain | "Bukan kelas X", tidak tercatat | | |
| F5 | Antrean 36 siswa | 36 siswa scan beruntun | Semua tercatat, tidak ada yang tertahan > 1 detik di depan scanner | | |
| F6 | Jaringan putus | Cabut Wi-Fi tablet, scan 5 siswa, sambungkan lagi | Hasil tampil saat offline; setelah tersambung kelimanya tercatat dengan jam scan asli | | |

## G. Orang tua (opsional, bila portal dinyalakan)

| No | Skenario | Langkah | Hasil yang diharapkan | L/G | Catatan |
| --- | --- | --- | --- | --- | --- |
| G1 | Masuk portal | Buka /portal → NIS + kode akses | Riwayat kehadiran anak per bulan dan ringkasan semester | | |
| G2 | Ganti ID | Ubah angka di alamat menjadi siswa lain | Ditolak (403) | | |
| G3 | Kode salah | Masukkan kode salah 5 kali | Diminta menunggu 15 menit | | |

## Lembar pengesahan

Dengan ini kami menyatakan skenario di atas telah diuji dan hasilnya sesuai.

| Peran | Nama | Tanda tangan | Tanggal |
| --- | --- | --- | --- |
| Tata Usaha | | | |
| Guru mata pelajaran | | | |
| Wali kelas | | | |
| Kepala sekolah | | | |
| Super Admin / pengembang | | | |
