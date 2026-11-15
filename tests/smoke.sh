#!/usr/bin/env bash
# Uji asap: jalankan aplikasi dengan SQLite, instal + data demo, lalu buka setiap halaman utama
# sebagai Super Admin, TU, Kepala Sekolah, dan Guru. Memastikan tidak ada 500, halaman lama 404,
# dan batas akses per peran (403 untuk kelas lain / halaman admin) berlaku.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${PORT:-8765}"
BASE="http://127.0.0.1:$PORT"
TMP="$(mktemp -d)"
trap 'kill "${PID:-0}" 2>/dev/null || true; rm -rf "$TMP"' EXIT

cd "$ROOT"
cp config/config.example.php config/config.php
sed -i "s/'driver' *=> *'mysql'/'driver' => 'sqlite'/" config/config.php
sed -i "s#'sqlite_path' *=> *[^,]*,#'sqlite_path' => '$TMP/smoke.sqlite',#" config/config.php
grep -q "$TMP/smoke.sqlite" config/config.php || { echo "sqlite_path tidak ditemukan di config"; exit 1; }
mkdir -p storage/logs

php -S "127.0.0.1:$PORT" -t public public/index.php > "$TMP/server.log" 2>&1 &
PID=$!
for _ in $(seq 1 30); do curl -s -o /dev/null "$BASE/install" && break; sleep 0.2; done

CJ="$TMP/cookies"
TOKEN=$(curl -s -c "$CJ" -b "$CJ" "$BASE/install" | grep -o 'name="_csrf" value="[^"]*"' | cut -d'"' -f4)
code=$(curl -s -o "$TMP/install.html" -w '%{http_code}' -c "$CJ" -b "$CJ" -X POST "$BASE/install" \
  --data-urlencode "_csrf=$TOKEN" --data-urlencode "name=Super Admin" --data-urlencode "username=superadmin" \
  --data-urlencode "password=SmokeTest12345" --data-urlencode "password_confirm=SmokeTest12345" --data-urlencode "demo=1")
[ "$code" = "200" ] || { echo "Instalasi gagal: HTTP $code"; head -50 "$TMP/install.html"; exit 1; }

fail=0
check() { # path expected-regex
  local got; got=$(curl -s -o "$TMP/last.html" -w '%{http_code}' -b "$CJ" "$BASE$1")
  if [[ "$got" =~ ^($2)$ ]]; then printf '  ok   %-40s %s\n' "$1" "$got"; else printf '  FAIL %-40s %s (harap %s)\n' "$1" "$got" "$2"; fail=1; fi
}
login() { # username password
  rm -f "$CJ"
  local tok; tok=$(curl -s -c "$CJ" -b "$CJ" "$BASE/masuk" | grep -o 'name="_csrf" value="[^"]*"' | cut -d'"' -f4)
  local got; got=$(curl -s -o /dev/null -w '%{http_code}' -c "$CJ" -b "$CJ" -X POST "$BASE/masuk" \
    --data-urlencode "_csrf=$tok" --data-urlencode "username=$1" --data-urlencode "password=$2")
  [[ "$got" =~ ^30[123]$ ]] || { echo "  FAIL login $1: HTTP $got"; fail=1; }
}

# Id kelas untuk uji akses guru01 (wali X-1): kelas sendiri vs kelas yang tidak diajar/diwalikan.
read -r OWN OTHER STUDENT_OWN STUDENT_OTHER < <(php -r '
$pdo = new PDO("sqlite:" . $argv[1]);
$g = (int) $pdo->query("SELECT id FROM users WHERE username = \"guru01\"")->fetchColumn();
$own = (int) $pdo->query("SELECT id FROM classes WHERE homeroom_teacher_id = $g")->fetchColumn();
$other = (int) $pdo->query("SELECT c.id FROM classes c WHERE c.homeroom_teacher_id <> $g AND NOT EXISTS (SELECT 1 FROM schedules h WHERE h.class_id = c.id AND h.teacher_id = $g) ORDER BY c.id DESC LIMIT 1")->fetchColumn();
$so = (int) $pdo->query("SELECT student_id FROM class_members WHERE class_id = $own LIMIT 1")->fetchColumn();
$st = (int) $pdo->query("SELECT student_id FROM class_members WHERE class_id = $other LIMIT 1")->fetchColumn();
echo "$own $other $so $st\n";' "$TMP/smoke.sqlite")
echo "Kelas guru01: sendiri=#$OWN lain=#$OTHER"

echo "Halaman Super Admin:"
for p in /admin /admin/pengaturan /admin/akun /admin/akun/baru /admin/akun/2 /admin/akun/2/ubah /admin/audit \
         /admin/tahun-ajaran "/admin/tahun-ajaran?ubah=1" /admin/kelas /admin/kelas/baru "/admin/kelas/$OWN" "/admin/kelas/$OWN/ubah" \
         /admin/siswa "/admin/siswa?kelas=tanpa&st=all" /admin/siswa/baru "/admin/siswa/$STUDENT_OWN" "/admin/siswa/$STUDENT_OWN/ubah" \
         "/admin/siswa/ekspor?format=csv" /admin/master/ruangan /admin/master/mapel /admin/master/jam /admin/master/libur \
         "/admin/master/ruangan?ubah=1" /admin/jadwal "/admin/jadwal?kelas=$OWN" "/admin/jadwal/baru?kelas=$OWN" /admin/jadwal/1/ubah \
         /admin/impor "/admin/impor/template/siswa?format=csv" "/admin/impor/template/jadwal?format=csv" /admin/kartu; do check "$p" 200; done

echo "Kartu QR: terbitkan satu kelas lewat form, lalu PDF:"
tok=$(curl -s -b "$CJ" "$BASE/admin/kartu" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
got=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" -X POST "$BASE/admin/kartu/terbitkan" --data-urlencode "_csrf=$tok" --data-urlencode "class_id=$OWN")
[[ "$got" =~ ^30[123]$ ]] && printf '  ok   %-40s %s\n' "POST /admin/kartu/terbitkan" "$got" || { printf '  FAIL POST /admin/kartu/terbitkan %s\n' "$got"; fail=1; }
t0=$(date +%s%N)
ctype=$(curl -s -o "$TMP/kartu.pdf" -w '%{content_type}' -b "$CJ" "$BASE/admin/kartu/cetak?kelas=$OWN")
ms=$(( ($(date +%s%N) - t0) / 1000000 ))
head -c 5 "$TMP/kartu.pdf" | grep -q '%PDF-' && [[ "$ctype" == application/pdf* ]] && printf '  ok   %-40s %s (%s ms)\n' "/admin/kartu/cetak?kelas=$OWN" "$ctype" "$ms" || { echo "  FAIL PDF kartu: $ctype"; fail=1; }
[ "$ms" -lt 5000 ] || { echo "  FAIL PDF satu kelas > 5 detik ($ms ms)"; fail=1; }
check "/admin/kartu/cetak?siswa=$STUDENT_OWN" 200
check "/admin/siswa/$STUDENT_OWN/foto" 404
check / 30[123]
check /install 404
check /admin/master/lainnya 404
check /admin/kelas/999999 404

echo "Halaman lama harus 404:"
for p in /petugas /petugas/scan /admin/event /admin/peserta /admin/laporan /admin/riwayat /e/demo /sertifikat; do check "$p" 404; done

echo "Impor CSV siswa (2 baris benar, 1 baris rusak) lewat form:"
printf 'nis,nisn,nama,jk,kelas\n99901,,Siswa Impor Satu,L,X-1\n99902,,Siswa Impor Dua,P,X-1\n,,Tanpa NIS,X,X-1\n' > "$TMP/siswa.csv"
tok=$(curl -s -b "$CJ" "$BASE/admin/impor" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
got=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" -X POST "$BASE/admin/impor/siswa" -F "_csrf=$tok" -F "mode=update" -F "file=@$TMP/siswa.csv")
[[ "$got" =~ ^30[123]$ ]] && printf '  ok   %-40s %s\n' "POST /admin/impor/siswa" "$got" || { printf '  FAIL POST /admin/impor/siswa %s\n' "$got"; fail=1; }
curl -s -b "$CJ" "$BASE/admin/impor" > "$TMP/impor.html"
grep -q "Siswa Impor" <(curl -s -b "$CJ" "$BASE/admin/siswa?q=Siswa+Impor") && echo "  ok   siswa hasil impor muncul di daftar" || { echo "  FAIL siswa hasil impor tidak muncul"; fail=1; }
grep -q 'Tanpa NIS\|Baris' "$TMP/impor.html" && echo "  ok   baris gagal dilaporkan" || { echo "  FAIL laporan baris gagal tidak muncul"; fail=1; }

echo "TU / Staff:"
login tu.sekolah tu12345678
for p in /admin /admin/siswa /admin/siswa/baru /admin/kelas "/admin/kelas/$OTHER" /admin/jadwal /admin/impor /admin/tahun-ajaran /admin/master/jam /admin/pengaturan; do check "$p" 200; done
for p in /admin/akun /admin/audit; do check "$p" 403; done

echo "Kepala Sekolah (hanya lihat):"
login kepsek kepsek12345
for p in /admin /admin/siswa /admin/kelas "/admin/kelas/$OTHER" /admin/jadwal "/admin/siswa/ekspor?format=csv"; do check "$p" 200; done
for p in /admin/siswa/baru /admin/kelas/baru /admin/impor /admin/akun /admin/audit /admin/master/ruangan /admin/tahun-ajaran /admin/kartu "/admin/kartu/cetak?kelas=$OWN"; do check "$p" 403; done

echo "Guru (wali kelas X-1):"
login guru01 guru12345
for p in /admin /admin/kelas "/admin/kelas/$OWN" "/admin/siswa?kelas=$OWN" "/admin/siswa/$STUDENT_OWN" "/admin/jadwal?kelas=$OWN" /admin/pengaturan; do check "$p" 200; done
for p in "/admin/kelas/$OTHER" "/admin/siswa?kelas=$OTHER" "/admin/siswa/$STUDENT_OTHER" "/admin/jadwal?kelas=$OTHER" \
         /admin/siswa/baru "/admin/kelas/$OWN/ubah" /admin/impor /admin/akun /admin/audit /admin/master/jam /admin/tahun-ajaran /admin/kartu "/admin/kartu/cetak?kelas=$OWN" "/admin/siswa/$STUDENT_OTHER/foto"; do check "$p" 403; done

echo "Tanpa login:"
rm -f "$CJ"
check /admin 30[123]
check /admin/siswa 30[123]
check /masuk 200

# Hanya error dari kode aplikasi (menyebut file di repo). Peringatan startup PHP seperti
# "JIT is incompatible ... in Unknown on line 0" berasal dari ekstensi runner, bukan dari aplikasi.
if grep -iE 'PHP (Fatal|Warning|Parse|Deprecated)|Uncaught' "$TMP/server.log" | grep -q "$ROOT/"; then
  echo "Server log berisi error PHP dari aplikasi:"; grep -iE 'PHP (Fatal|Warning|Parse|Deprecated)|Uncaught' "$TMP/server.log" | grep "$ROOT/" | head; fail=1
fi

rm -f config/config.php
[ "$fail" = 0 ] && echo "SMOKE OK" || { echo "SMOKE GAGAL"; exit 1; }
