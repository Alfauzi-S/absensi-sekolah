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
         /admin/impor "/admin/impor/template/siswa?format=csv" "/admin/impor/template/jadwal?format=csv" /admin/kartu \
         /admin/perangkat /admin/sesi "/admin/sesi?tanggal=2026-11-16" /admin/kecepatan "/admin/kecepatan?hari=1" /kiosk /kiosk-sw.js; do check "$p" 200; done

echo "Kartu QR: terbitkan satu kelas lewat form, lalu PDF:"
tok=$(curl -s -b "$CJ" "$BASE/admin/kartu" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
got=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" -X POST "$BASE/admin/kartu/terbitkan" --data-urlencode "_csrf=$tok" --data-urlencode "class_id=$OWN")
[[ "$got" =~ ^30[123]$ ]] && printf '  ok   %-40s %s\n' "POST /admin/kartu/terbitkan" "$got" || { printf '  FAIL POST /admin/kartu/terbitkan %s\n' "$got"; fail=1; }
curl -s -o /dev/null -b "$CJ" -c "$CJ" -X POST "$BASE/admin/kartu/terbitkan" --data-urlencode "_csrf=$tok" --data-urlencode "class_id=$OTHER"
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

echo "Scanner (Fase 3): perangkat, pairing, API scan, 50 scan ganda bersamaan:"
tok=$(curl -s -b "$CJ" "$BASE/admin/perangkat" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
got=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" -X POST "$BASE/admin/perangkat/baru" --data-urlencode "_csrf=$tok" --data-urlencode "name=Tablet Smoke" --data-urlencode "room_id=1")
[[ "$got" =~ ^30[123]$ ]] && printf '  ok   %-40s %s\n' "POST /admin/perangkat/baru" "$got" || { printf '  FAIL POST /admin/perangkat/baru %s\n' "$got"; fail=1; }
tok=$(curl -s -b "$CJ" "$BASE/admin/sesi" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
got=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" -X POST "$BASE/admin/sesi/baru" --data-urlencode "_csrf=$tok" --data-urlencode "class_id=$OWN" --data-urlencode "room_id=1" \
  --data-urlencode "date=$(php -r 'date_default_timezone_set("Asia/Makassar"); echo date("Y-m-d");')" --data-urlencode "start=$(php -r 'date_default_timezone_set("Asia/Makassar"); echo date("H:i", time() - 1200);')" --data-urlencode "end=$(php -r 'date_default_timezone_set("Asia/Makassar"); echo date("H:i", min(time() + 5400, strtotime("23:59")));')" --data-urlencode "note=Smoke")
[[ "$got" =~ ^30[123]$ ]] && printf '  ok   %-40s %s\n' "POST /admin/sesi/baru (sesi tambahan)" "$got" || { printf '  FAIL POST /admin/sesi/baru %s\n' "$got"; fail=1; }
read -r PAIR CARD SESS < <(php -r '
$pdo = new PDO("sqlite:" . $argv[1]);
echo $pdo->query("SELECT pair_code FROM devices ORDER BY id DESC LIMIT 1")->fetchColumn(), " ",
  $pdo->query("SELECT code FROM cards WHERE student_id = " . (int) $argv[2] . " AND status = \"active\"")->fetchColumn(), " ",
  $pdo->query("SELECT id FROM sessions ORDER BY id DESC LIMIT 1")->fetchColumn(), "\n";' "$TMP/smoke.sqlite" "$STUDENT_OWN")
api() { curl -s -o "$TMP/api.json" -w '%{http_code}' -X POST "$BASE$1" -H "Content-Type: application/json" ${2:+-H "X-Device-Token: $2"} -d "$3"; }
got=$(api /api/device/pair "" '{"code":"000000"}'); [ "$got" = 400 ] && echo "  ok   pairing kode salah → 400" || { echo "  FAIL pairing kode salah: $got"; fail=1; }
got=$(api /api/device/pair "" "{\"code\":\"$PAIR\"}"); DTOK=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["token"] ?? "";' "$TMP/api.json")
[ "$got" = 200 ] && [ -n "$DTOK" ] && echo "  ok   pairing kode benar → token" || { echo "  FAIL pairing: $got $(cat "$TMP/api.json")"; fail=1; }
got=$(api /api/device/pair "" "{\"code\":\"$PAIR\"}"); [ "$got" = 400 ] && echo "  ok   kode pasangan sekali pakai" || { echo "  FAIL kode dipakai dua kali: $got"; fail=1; }
got=$(api /api/scan "" '{"code":"X"}'); [ "$got" = 401 ] && echo "  ok   scan tanpa token → 401" || { echo "  FAIL scan tanpa token: $got"; fail=1; }
got=$(api /api/scan "salah" '{"code":"X"}'); [ "$got" = 401 ] && echo "  ok   scan token salah → 401" || { echo "  FAIL scan token salah: $got"; fail=1; }
got=$(curl -s -o "$TMP/me.json" -w '%{http_code}' "$BASE/api/device/me" -H "X-Device-Token: $DTOK"); grep -q '"Smoke' "$TMP/me.json" && [ "$got" = 200 ] && echo "  ok   /api/device/me memuat sesi tambahan" || { echo "  FAIL /api/device/me: $got $(cat "$TMP/me.json")"; fail=1; }
# 50 scan siswa yang sama secara paralel: tepat 1 tersimpan, 49 duplikat
seq 1 50 | xargs -P 25 -I{} curl -s -o "$TMP/scan-{}.json" -X POST "$BASE/api/scan" -H "Content-Type: application/json" -H "X-Device-Token: $DTOK" -d "{\"scan_id\":\"smoke-{}-00000000\",\"code\":\"$CARD\"}"
n_ok=$(cat "$TMP"/scan-*.json | grep -o '"state":"\(ok\|late\)"' | wc -l || true); n_dup=$(cat "$TMP"/scan-*.json | grep -o '"state":"dup"' | wc -l || true)
rows=$(php -r '$p = new PDO("sqlite:" . $argv[1]); echo $p->query("SELECT COUNT(*) FROM attendances WHERE student_id = " . (int) $argv[2] . " AND session_id = " . (int) $argv[3])->fetchColumn();' "$TMP/smoke.sqlite" "$STUDENT_OWN" "$SESS")
[ "$n_ok" = 1 ] && [ "$n_dup" = 49 ] && [ "$rows" = 1 ] && echo "  ok   50 scan bersamaan: 1 tersimpan, 49 duplikat, 1 baris" || { echo "  FAIL 50 scan bersamaan: ok=$n_ok dup=$n_dup baris=$rows"; fail=1; }
got=$(api /api/scan "$DTOK" "{\"code\":\"$(php -r '$p = new PDO("sqlite:" . $argv[1]); echo $p->query("SELECT code FROM cards WHERE student_id = " . (int) $argv[2])->fetchColumn();' "$TMP/smoke.sqlite" "$STUDENT_OTHER")\"}")
grep -q '"state":"wrongclass"' "$TMP/api.json" && echo "  ok   siswa kelas lain → salah kelas" || { echo "  FAIL salah kelas: $(cat "$TMP/api.json")"; fail=1; }
echo "Fase 4: roster, batch 3 kali, metrik:"
got=$(curl -s -o "$TMP/roster.json" -w '%{http_code}' "$BASE/api/roster" -H "X-Device-Token: $DTOK"); RV=$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo count($d["students"] ?? []) > 0 && count($d["sessions"] ?? []) > 0 ? $d["version"] : "";' "$TMP/roster.json")
[ "$got" = 200 ] && [ -n "$RV" ] && echo "  ok   /api/roster memuat siswa dan sesi" || { echo "  FAIL /api/roster: $got $(head -c 300 "$TMP/roster.json")"; fail=1; }
curl -s "$BASE/api/roster?v=$RV" -H "X-Device-Token: $DTOK" | grep -q '"unchanged":true' && echo "  ok   roster versi sama → unchanged" || { echo "  FAIL roster unchanged"; fail=1; }
got=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/roster"); [ "$got" = 401 ] && echo "  ok   roster tanpa token → 401" || { echo "  FAIL roster tanpa token: $got"; fail=1; }
php -r 'date_default_timezone_set("Asia/Makassar"); $p = new PDO("sqlite:" . $argv[1]); $items = [];
foreach ($p->query("SELECT c.code FROM cards c JOIN class_members m ON m.student_id = c.student_id WHERE m.class_id = " . (int) $argv[2] . " AND c.status = \"active\" AND c.student_id <> " . (int) $argv[3] . " LIMIT 5")->fetchAll(PDO::FETCH_COLUMN) as $i => $c) {
  $items[] = ["scan_id" => "smoke-batch-0000000" . $i, "code" => $c, "scanned_at" => date("c", time() - 300 + $i)];
  $met[] = ["scan_id" => "smoke-batch-0000000" . $i, "source" => "usb", "t_feedback" => 5, "t_total" => 5, "queued" => true]; }
echo json_encode(["sent_at" => date("c"), "scans" => $items, "metrics" => $met]);' "$TMP/smoke.sqlite" "$OWN" "$STUDENT_OWN" > "$TMP/batch.json"
for i in 1 2 3; do got=$(curl -s -o "$TMP/batch-out.json" -w '%{http_code}' -X POST "$BASE/api/scan/batch" -H "Content-Type: application/json" -H "X-Device-Token: $DTOK" --data-binary "@$TMP/batch.json"); done
read -r B_ROWS B_LATE B_MET < <(php -r '$p = new PDO("sqlite:" . $argv[1]); $s = (int) $argv[2];
echo $p->query("SELECT COUNT(*) FROM attendances WHERE session_id = $s AND student_id <> " . (int) $argv[3])->fetchColumn(), " ",
  $p->query("SELECT COUNT(*) FROM attendances WHERE session_id = $s AND student_id <> " . (int) $argv[3] . " AND scanned_at > datetime(\"now\", \"+8 hours\", \"-200 seconds\")")->fetchColumn(), " ",
  $p->query("SELECT COUNT(*) FROM scan_metrics WHERE scan_id LIKE \"smoke-batch-%\"")->fetchColumn(), "\n";' "$TMP/smoke.sqlite" "$SESS" "$STUDENT_OWN")
[ "$got" = 200 ] && [ "$B_ROWS" = 5 ] && [ "$B_LATE" = 0 ] && [ "$B_MET" = 5 ] && echo "  ok   batch 3 kali: 5 baris, waktu scan = waktu di perangkat, 5 metrik" || { echo "  FAIL batch: http=$got baris=$B_ROWS waktu_sinkron=$B_LATE metrik=$B_MET $(head -c 300 "$TMP/batch-out.json")"; fail=1; }
check /admin/kecepatan 200
echo "Fase 5: izin, koreksi, rekap, ekspor:"
TODAY=$(php -r 'date_default_timezone_set("Asia/Makassar"); echo date("Y-m-d");')
for p in /admin/izin "/admin/izin/baru?kelas=$OWN" /admin/rekap "/admin/rekap/kelas/$OWN" "/admin/rekap/kelas/$OWN?jenis=harian" "/admin/rekap/kelas/$OWN?jenis=semester" \
         "/admin/rekap/siswa/$STUDENT_OWN" /admin/rekap/sekolah "/admin/rekap/sekolah?jenis=semester"; do check "$p" 200; done
check "/admin/rekap/kelas?id=$OWN&jenis=bulanan" 30[123]
for q in "kelas/$OWN?format=xlsx" "kelas/$OWN?jenis=semester&format=xlsx" "kelas/$OWN?jenis=harian&format=csv" "siswa/$STUDENT_OWN?format=xlsx" "sekolah?format=csv" "sekolah/semester"; do
  ct=$(curl -s -o "$TMP/rekap.bin" -w '%{http_code} %{content_type}' -b "$CJ" "$BASE/admin/rekap/$q")
  if [[ "$ct" == "200 application/vnd.openxmlformats"* ]] && head -c 2 "$TMP/rekap.bin" | grep -q PK; then printf '  ok   %-40s xlsx\n' "/admin/rekap/$q";
  elif [[ "$ct" == "200 text/csv"* ]]; then printf '  ok   %-40s csv\n' "/admin/rekap/$q"; else echo "  FAIL /admin/rekap/$q: $ct"; fail=1; fi
done
tok=$(curl -s -b "$CJ" "$BASE/admin/izin/baru?kelas=$OWN" | grep -o 'name="_csrf" value="[^"]*"' | tail -1 | cut -d'"' -f4)
got=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" -X POST "$BASE/admin/izin" --data-urlencode "_csrf=$tok" --data-urlencode "class_id=$OWN" --data-urlencode "student_id=$STUDENT_OWN" \
  --data-urlencode "kind=S" --data-urlencode "date_from=$TODAY" --data-urlencode "date_to=$TODAY" --data-urlencode "reason=Demam, surat dokter")
st=$(php -r '$p = new PDO("sqlite:" . $argv[1]); echo $p->query("SELECT status FROM attendances WHERE session_id = " . (int) $argv[2] . " AND student_id = " . (int) $argv[3])->fetchColumn();' "$TMP/smoke.sqlite" "$SESS" "$STUDENT_OWN")
[[ "$got" =~ ^30[123]$ ]] && [ "$st" = S ] && echo "  ok   izin sakit menimpa hasil scan (H → S)" || { echo "  FAIL izin: http=$got status=$st"; fail=1; }
tok=$(curl -s -b "$CJ" "$BASE/admin/sesi/$SESS" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -o /dev/null -b "$CJ" -c "$CJ" -X POST "$BASE/admin/sesi/$SESS/koreksi" --data-urlencode "_csrf=$tok" --data-urlencode "student_id=$STUDENT_OWN" --data-urlencode "status=H" --data-urlencode "reason="
st=$(php -r '$p = new PDO("sqlite:" . $argv[1]); echo $p->query("SELECT status FROM attendances WHERE session_id = " . (int) $argv[2] . " AND student_id = " . (int) $argv[3])->fetchColumn();' "$TMP/smoke.sqlite" "$SESS" "$STUDENT_OWN")
[ "$st" = S ] && echo "  ok   koreksi tanpa alasan ditolak" || { echo "  FAIL koreksi tanpa alasan diterima: $st"; fail=1; }
curl -s -o /dev/null -b "$CJ" -c "$CJ" -X POST "$BASE/admin/sesi/$SESS/koreksi" --data-urlencode "_csrf=$tok" --data-urlencode "student_id=$STUDENT_OWN" --data-urlencode "status=H" --data-urlencode "reason=Sudah sembuh, hadir"
read -r st hist < <(php -r '$p = new PDO("sqlite:" . $argv[1]); echo $p->query("SELECT status FROM attendances WHERE session_id = " . (int) $argv[2] . " AND student_id = " . (int) $argv[3])->fetchColumn(), " ", $p->query("SELECT COUNT(*) FROM status_history WHERE session_id = " . (int) $argv[2])->fetchColumn(), "\n";' "$TMP/smoke.sqlite" "$SESS" "$STUDENT_OWN")
[ "$st" = H ] && [ "$hist" -ge 2 ] && echo "  ok   koreksi dengan alasan → H, riwayat $hist baris" || { echo "  FAIL koreksi: status=$st riwayat=$hist"; fail=1; }
check "/admin/sesi/$SESS" 200
check "/admin/sesi/$SESS/live" 200
echo "Fase 6: notifikasi WhatsApp, portal orang tua:"
sqlset() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->prepare("INSERT INTO settings (skey, svalue, updated_at) VALUES (?, ?, datetime()) ON CONFLICT(skey) DO UPDATE SET svalue = excluded.svalue")->execute([$argv[2], $argv[3]]);' "$TMP/smoke.sqlite" "$1" "$2"; }
check /admin/notifikasi 200
check /portal 404
grep -q 'parent_phone\|"phone"' "$TMP/roster.json" && { echo "  FAIL roster kiosk berisi nomor HP"; fail=1; } || echo "  ok   roster kiosk tanpa nomor HP orang tua"
sqlset wa_notify both; sqlset wa_provider sandbox; sqlset portal_enabled 1
SBX="$ROOT/storage/logs/wa-sandbox.log"; before=$( [ -f "$SBX" ] && wc -l < "$SBX" || echo 0 )
tok=$(curl -s -b "$CJ" "$BASE/admin/notifikasi" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -o /dev/null -b "$CJ" -c "$CJ" -X POST "$BASE/admin/notifikasi/uji" --data-urlencode "_csrf=$tok" --data-urlencode "phone=0812-0000-0001"
after=$( [ -f "$SBX" ] && wc -l < "$SBX" || echo 0 )
[ "$after" -gt "$before" ] && echo "  ok   pesan uji sandbox tercatat" || { echo "  FAIL pesan uji sandbox tidak tercatat"; fail=1; }
got=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" -X POST "$BASE/admin/notifikasi/kirim" --data-urlencode "_csrf=$tok")
[[ "$got" =~ ^30[123]$ ]] && echo "  ok   kirim antrean sekarang → $got" || { echo "  FAIL kirim antrean: $got"; fail=1; }
check /admin/notifikasi 200
# Kode portal untuk satu siswa (ditampilkan sekali) dan CSV per kelas
curl -s -o /dev/null -b "$CJ" -c "$CJ" -X POST "$BASE/admin/siswa/$STUDENT_OWN/kode-portal" --data-urlencode "_csrf=$tok"
PCODE=$(curl -s -b "$CJ" -c "$CJ" "$BASE/admin/siswa/$STUDENT_OWN" | grep -o 'data-portal-code>[A-Z0-9-]*' | cut -d'>' -f2)
[ -n "$PCODE" ] && echo "  ok   kode portal ditampilkan sekali ($PCODE)" || { echo "  FAIL kode portal tidak tampil"; fail=1; }
curl -s -b "$CJ" "$BASE/admin/siswa/$STUDENT_OWN" | grep -q 'data-portal-code' && { echo "  FAIL kode portal tampil lagi"; fail=1; } || echo "  ok   kode tidak tampil lagi setelah dimuat ulang"
curl -s -D "$TMP/h.txt" -o "$TMP/kode.csv" -b "$CJ" -X POST "$BASE/admin/kelas/$OTHER/kode-portal" --data-urlencode "_csrf=$tok"
grep -qi 'content-type: text/csv' "$TMP/h.txt" && grep -q 'Kode akses' "$TMP/kode.csv" && echo "  ok   CSV kode portal per kelas ($(($(wc -l < "$TMP/kode.csv") - 1)) siswa)" || { echo "  FAIL CSV kode portal"; fail=1; }
NIS_OWN=$(php -r '$p = new PDO("sqlite:" . $argv[1]); echo $p->query("SELECT nis FROM students WHERE id = " . (int) $argv[2])->fetchColumn();' "$TMP/smoke.sqlite" "$STUDENT_OWN")
PJ="$TMP/portal"
ptok=$(curl -s -c "$PJ" -b "$PJ" "$BASE/portal" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
got=$(curl -s -o /dev/null -w '%{http_code}' -c "$PJ" -b "$PJ" -X POST "$BASE/portal/masuk" --data-urlencode "_csrf=$ptok" --data-urlencode "nis=$NIS_OWN" --data-urlencode "code=ZZZZ-ZZZZ")
[ "$got" = 422 ] && echo "  ok   portal: kode salah ditolak" || { echo "  FAIL portal kode salah: $got"; fail=1; }
got=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c "$PJ" -b "$PJ" -X POST "$BASE/portal/masuk" --data-urlencode "_csrf=$ptok" --data-urlencode "nis=$NIS_OWN" --data-urlencode "code=$PCODE")
[[ "$got" =~ ^30[123].*/portal/siswa/$STUDENT_OWN$ ]] && echo "  ok   portal: masuk dengan NIS + kode" || { echo "  FAIL portal masuk: $got"; fail=1; }
pcheck() { local got; got=$(curl -s -o "$TMP/p.html" -w '%{http_code}' -b "$PJ" "$BASE$1"); [[ "$got" =~ ^($2)$ ]] && printf '  ok   %-40s %s\n' "$1" "$got" || { printf '  FAIL %-40s %s (harap %s)\n' "$1" "$got" "$2"; fail=1; }; }
pcheck "/portal/siswa/$STUDENT_OWN" 200
pcheck "/portal/siswa/$STUDENT_OWN?bulan=2026-01" 200
pcheck "/portal/siswa/$STUDENT_OTHER" 403
pcheck "/portal/siswa/$((STUDENT_OWN + 1))" 403
pcheck /admin 30[123]
grep -q 'parent_phone\|62812' "$TMP/p.html" && { echo "  FAIL halaman portal berisi nomor HP"; fail=1; } || true
tok=$(curl -s -b "$CJ" "$BASE/admin/perangkat" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -o /dev/null -b "$CJ" -c "$CJ" -X POST "$BASE/admin/perangkat/1/cabut" --data-urlencode "_csrf=$tok"
got=$(api /api/scan "$DTOK" '{"code":"X"}'); [ "$got" = 401 ] && echo "  ok   perangkat dicabut → token ditolak" || { echo "  FAIL token dicabut masih diterima: $got"; fail=1; }

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
for p in /admin /admin/siswa /admin/siswa/baru /admin/kelas "/admin/kelas/$OTHER" /admin/jadwal /admin/impor /admin/tahun-ajaran /admin/master/jam /admin/pengaturan /admin/sesi "/admin/sesi/$SESS"; do check "$p" 200; done
for p in /admin/izin "/admin/izin/baru?kelas=$OTHER" "/admin/rekap/kelas/$OTHER" "/admin/rekap/siswa/$STUDENT_OTHER" /admin/rekap/sekolah; do check "$p" 200; done
for p in /admin/akun /admin/audit /admin/perangkat /admin/kecepatan /admin/notifikasi; do check "$p" 403; done

echo "Kepala Sekolah (hanya lihat):"
login kepsek kepsek12345
for p in /admin /admin/siswa /admin/kelas "/admin/kelas/$OTHER" /admin/jadwal "/admin/siswa/ekspor?format=csv" /admin/sesi "/admin/sesi/$SESS"; do check "$p" 200; done
for p in /admin/siswa/baru /admin/kelas/baru /admin/impor /admin/akun /admin/audit /admin/master/ruangan /admin/tahun-ajaran /admin/kartu "/admin/kartu/cetak?kelas=$OWN" /admin/perangkat /admin/kecepatan /admin/izin/baru; do check "$p" 403; done
for p in /admin/izin "/admin/rekap/kelas/$OTHER" /admin/rekap/sekolah "/admin/rekap/sekolah?format=csv"; do check "$p" 200; done

echo "Guru (wali kelas X-1):"
login guru01 guru12345
for p in /admin /admin/kelas "/admin/kelas/$OWN" "/admin/siswa?kelas=$OWN" "/admin/siswa/$STUDENT_OWN" "/admin/jadwal?kelas=$OWN" /admin/pengaturan /admin/sesi "/admin/sesi/$SESS" "/admin/sesi/$SESS/live"; do check "$p" 200; done
for p in "/admin/kelas/$OTHER" "/admin/siswa?kelas=$OTHER" "/admin/siswa/$STUDENT_OTHER" "/admin/jadwal?kelas=$OTHER" \
         /admin/siswa/baru "/admin/kelas/$OWN/ubah" /admin/impor /admin/akun /admin/audit /admin/master/jam /admin/tahun-ajaran /admin/kartu "/admin/kartu/cetak?kelas=$OWN" "/admin/siswa/$STUDENT_OTHER/foto" /admin/perangkat /admin/kecepatan /admin/notifikasi \
         "/admin/rekap/kelas/$OTHER" "/admin/rekap/siswa/$STUDENT_OTHER" /admin/rekap/sekolah "/admin/izin/baru?kelas=$OTHER" "/admin/izin?kelas=$OTHER"; do check "$p" 403; done
for p in /admin/izin "/admin/izin/baru?kelas=$OWN" /admin/rekap "/admin/rekap/kelas/$OWN" "/admin/rekap/siswa/$STUDENT_OWN"; do check "$p" 200; done

echo "Tanpa login:"
rm -f "$CJ"
check /admin 30[123]
check /admin/siswa 30[123]
check /masuk 200
check /kiosk 200
check /api/device/me 401

# Hanya error dari kode aplikasi (menyebut file di repo). Peringatan startup PHP seperti
# "JIT is incompatible ... in Unknown on line 0" berasal dari ekstensi runner, bukan dari aplikasi.
if grep -iE 'PHP (Fatal|Warning|Parse|Deprecated)|Uncaught' "$TMP/server.log" | grep -q "$ROOT/"; then
  echo "Server log berisi error PHP dari aplikasi:"; grep -iE 'PHP (Fatal|Warning|Parse|Deprecated)|Uncaught' "$TMP/server.log" | grep "$ROOT/" | head; fail=1
fi

rm -f config/config.php
[ "$fail" = 0 ] && echo "SMOKE OK" || { echo "SMOKE GAGAL"; exit 1; }
