# Keamanan dan Privasi

## Akun dan akses

- **Password** disimpan dalam bentuk hash; tidak ada yang bisa membacanya, termasuk Super Admin.
- **Batas login**: lima kali salah dalam 15 menit membuat login dari alamat itu ditahan sementara.
- **Akses diperiksa di server**: guru yang mengganti alamat URL ke kelas lain tetap ditolak.
- **Audit log** mencatat login, perubahan data, koreksi, dan akses yang ditolak. Isinya tidak pernah memuat password atau token.

## Kartu dan perangkat

- **Kode kartu** acak dan tidak berisi NIS atau nama. Kartu yang hilang diganti; kartu lama langsung tidak berlaku.
- **Perangkat kiosk** memakai token sendiri, bukan akun pengguna. Perangkat yang hilang dicabut dari halaman Perangkat Scanner.
- **Kode pasangan** hanya berlaku 15 menit dan dibatasi 10 percobaan per 10 menit.

## Data siswa

- **Foto siswa** hanya tampil di kiosk ruangan siswa dan untuk pengguna yang berhak.
- **Portal orang tua** hanya menampilkan riwayat anak dari NIS yang dimasukkan.
- **Cadangan** berisi data pribadi siswa. Simpan salinannya di tempat yang hanya bisa dibuka pengelola.
- **Ekspor** Excel/CSV aman dari formula berbahaya.

## Kebiasaan yang disarankan

- Jangan berbagi akun. Setiap guru memakai akunnya sendiri agar audit log bermakna.
- Keluar dari aplikasi di komputer bersama.
- Gunakan HTTPS. Tanpa HTTPS, kamera tablet tidak bisa dipakai dan password terkirim tanpa enkripsi.
