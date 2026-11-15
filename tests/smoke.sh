#!/usr/bin/env bash
# Uji asap: jalankan aplikasi dengan SQLite, instal + data demo, lalu buka setiap halaman utama.
# Gagal bila ada halaman yang mengembalikan 500, atau halaman yang sudah dihapus masih bisa dibuka.
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
[ "$code" = "200" ] || { echo "Instalasi gagal: HTTP $code"; cat "$TMP/install.html" | head -50; exit 1; }

fail=0
check() { # path expected-regex
  local got; got=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" "$BASE$1")
  if [[ "$got" =~ ^($2)$ ]]; then printf '  ok   %-34s %s\n' "$1" "$got"; else printf '  FAIL %-34s %s (harap %s)\n' "$1" "$got" "$2"; fail=1; fi
}

echo "Halaman Super Admin:"
for p in /admin /admin/event /admin/event/1 /admin/event/baru /admin/akun /admin/akun/baru /admin/akun/2 \
         /admin/peserta /admin/peserta/1 /admin/laporan /admin/riwayat /admin/audit /admin/pengaturan \
         /petugas/scan /petugas/peserta "/admin/ekspor?format=csv&ev=1"; do check "$p" 200; done
check / 30[123]
check /install 404

echo "Halaman yang sudah dibuang harus 404:"
for p in /sertifikat /v/abc /admin/sertifikat /e/demo /e/demo/daftar /t/SC12345678; do check "$p" 404; done

echo "API scan (tanpa CSRF harus ditolak, bukan 500):"
check_post() {
  local got; got=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -H 'Content-Type: application/json' -X POST "$BASE$1" -d '{}')
  if [[ "$got" =~ ^($2)$ ]]; then printf '  ok   %-34s %s\n' "$1" "$got"; else printf '  FAIL %-34s %s (harap %s)\n' "$1" "$got" "$2"; fail=1; fi
}
check_post /api/scan/validate '4[0-9][0-9]'
check_post /api/scan/save '4[0-9][0-9]'

if grep -qiE 'PHP (Fatal|Warning|Parse)|Uncaught' "$TMP/server.log"; then
  echo "Server log berisi error PHP:"; grep -iE 'PHP (Fatal|Warning|Parse)|Uncaught' "$TMP/server.log" | head; fail=1
fi

rm -f config/config.php
[ "$fail" = 0 ] && echo "SMOKE OK" || { echo "SMOKE GAGAL"; exit 1; }
