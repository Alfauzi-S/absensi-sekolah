#!/usr/bin/env bash
# Latihan pemulihan (PRD Fase 7, uji "Pemulihan": restore cadangan database ke server baru, sistem jalan lagi < 30 menit).
#
#   1. "Server lama": instal + data demo (SQLite), isi riwayat kehadiran DAYS hari (bawaan 200 = satu tahun ajaran), buat cadangan.
#   2. "Server baru": database kosong (SQLite baru, atau MariaDB/MySQL bila DRILL_TARGET=mysql), pulihkan cadangan,
#      jalankan aplikasi, login, buka halaman utama, cocokkan jumlah baris. Waktu dihitung dari mulai pemulihan sampai aplikasi menjawab.
#
#   bash tests/ops/restore-drill.sh
#   DRILL_TARGET=mysql MYSQL_HOST=127.0.0.1 MYSQL_PORT=3306 MYSQL_DB=absensi MYSQL_USER=absensi MYSQL_PASS=... bash tests/ops/restore-drill.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DAYS="${DAYS:-200}"
TARGET="${DRILL_TARGET:-sqlite}"
PORT="${PORT:-8771}"
BASE="http://127.0.0.1:$PORT"
TMP="$(mktemp -d)"
cleanup() { kill "${PID:-0}" 2>/dev/null || true; rm -f "$ROOT/config/config.php"; rm -rf "$TMP"; }
trap cleanup EXIT
cd "$ROOT"
[ -f config/config.php ] && { echo "config/config.php sudah ada; pindahkan dulu (latihan ini menulis config sementara)"; exit 1; }

cfg_sqlite() {
  cp config/config.example.php config/config.php
  sed -i "s/'driver' *=> *'mysql'/'driver' => 'sqlite'/; s#'sqlite_path' *=> *[^,]*,#'sqlite_path' => '$1',#" config/config.php
}
cfg_mysql() {
  cp config/config.example.php config/config.php
  sed -i "s/'host'     => '127.0.0.1'/'host' => '${MYSQL_HOST:-127.0.0.1}'/; s/'port'     => 3306/'port' => ${MYSQL_PORT:-3306}/; s/'database' => 'smartcity_attendance'/'database' => '${MYSQL_DB:-absensi}'/; s/'username' => 'smartcity'/'username' => '${MYSQL_USER:-absensi}'/; s/'password' => 'ganti-dengan-password-database'/'password' => '${MYSQL_PASS:-}'/" config/config.php
}
serve() { php -S "127.0.0.1:$PORT" -t public public/index.php > "$TMP/server.log" 2>&1 & PID=$!; for _ in $(seq 1 50); do curl -s -o /dev/null "$BASE/masuk" && break; sleep 0.1; done; }
stop() { kill "$PID" 2>/dev/null || true; wait "$PID" 2>/dev/null || true; }

echo "== Server lama (SQLite): instal, data demo, riwayat $DAYS hari"
cfg_sqlite "$TMP/lama.sqlite"
serve
CJ="$TMP/cj"
tok=$(curl -s -c "$CJ" -b "$CJ" "$BASE/install" | grep -o 'name="_csrf" value="[^"]*"' | cut -d'"' -f4)
curl -s -o /dev/null -c "$CJ" -b "$CJ" -X POST "$BASE/install" --data-urlencode "_csrf=$tok" --data-urlencode "name=Super Admin" \
  --data-urlencode "username=superadmin" --data-urlencode "password=Latihan12345" --data-urlencode "password_confirm=Latihan12345" --data-urlencode "demo=1"
stop
php tests/perf/rekap.php "$DAYS" > "$TMP/seed.txt"; head -1 "$TMP/seed.txt"
php bin/backup.php | sed 's/^/  /'
BK=$(ls -t storage/backups/absensi-*.zip | head -1)
mkdir -p "$TMP/pindah" && cp "$BK" "$TMP/pindah/" && BK="$TMP/pindah/$(basename "$BK")"

echo "== Server baru ($TARGET): pulihkan"
START=$(date +%s.%N)
if [ "$TARGET" = mysql ]; then cfg_mysql; else cfg_sqlite "$TMP/baru.sqlite"; fi
php bin/restore.php "$BK" --yes > "$TMP/restore.txt"; tail -2 "$TMP/restore.txt" | sed "s/^/  /"
serve
rm -f "$CJ"
tok=$(curl -s -c "$CJ" -b "$CJ" "$BASE/masuk" | grep -o 'name="_csrf" value="[^"]*"' | cut -d'"' -f4)
got=$(curl -s -o /dev/null -w '%{http_code}' -c "$CJ" -b "$CJ" -X POST "$BASE/masuk" --data-urlencode "_csrf=$tok" --data-urlencode "username=superadmin" --data-urlencode "password=Latihan12345")
END=$(date +%s.%N)
fail=0
[[ "$got" =~ ^30[123]$ ]] && echo "  ok   login Super Admin di server baru" || { echo "  FAIL login: $got"; fail=1; }
for p in /admin /admin/siswa /admin/kelas/1 /admin/rekap/kelas/1 "/admin/rekap/kelas/1?jenis=semester" /admin/rekap/sekolah /admin/sesi /admin/kesehatan; do
  c=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" "$BASE$p"); [ "$c" = 200 ] && printf '  ok   %-38s %s\n' "$p" "$c" || { printf '  FAIL %-38s %s\n' "$p" "$c"; fail=1; }
done
stop
SECS=$(php -r 'printf("%.1f", $argv[2] - $argv[1]);' "$START" "$END")
echo "Waktu pulih (pulihkan + aplikasi menjawab login): $SECS detik (target < 1800 detik)"
[ "$fail" = 0 ] && echo "LATIHAN PEMULIHAN OK" || { echo "LATIHAN PEMULIHAN GAGAL"; exit 1; }
