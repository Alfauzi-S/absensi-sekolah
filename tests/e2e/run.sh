#!/usr/bin/env bash
# E2E kiosk: server PHP + SQLite + data demo, satu perangkat scanner untuk ruangan kelas X-1, kartu untuk X-1 dan X-2,
# lalu Playwright mengetik 36 kode siswa seperti scanner USB. Butuh: php, node, playwright + Chromium.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PORT="${PORT:-8766}"
BASE="http://127.0.0.1:$PORT"
TMP="$(mktemp -d)"
trap 'kill "${PID:-0}" 2>/dev/null || true; rm -rf "$TMP"' EXIT
cd "$ROOT"
cp config/config.example.php config/config.php
sed -i "s/'driver' *=> *'mysql'/'driver' => 'sqlite'/" config/config.php
sed -i "s#'sqlite_path' *=> *[^,]*,#'sqlite_path' => '$TMP/e2e.sqlite',#" config/config.php
mkdir -p storage/logs
php -S "127.0.0.1:$PORT" -t public public/index.php > "$TMP/server.log" 2>&1 &
PID=$!
for _ in $(seq 1 30); do curl -s -o /dev/null "$BASE/install" && break; sleep 0.2; done
CJ="$TMP/cookies"
TOKEN=$(curl -s -c "$CJ" -b "$CJ" "$BASE/install" | grep -o 'name="_csrf" value="[^"]*"' | cut -d'"' -f4)
curl -s -o /dev/null -c "$CJ" -b "$CJ" -X POST "$BASE/install" --data-urlencode "_csrf=$TOKEN" --data-urlencode "name=Super Admin" \
  --data-urlencode "username=superadmin" --data-urlencode "password=E2eTest12345" --data-urlencode "password_confirm=E2eTest12345" --data-urlencode "demo=1"
tok=$(curl -s -b "$CJ" "$BASE/admin/kartu" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -o /dev/null -b "$CJ" -c "$CJ" -X POST "$BASE/admin/kartu/terbitkan" --data-urlencode "_csrf=$tok" --data-urlencode "class_id=1"
curl -s -o /dev/null -b "$CJ" -c "$CJ" -X POST "$BASE/admin/kartu/terbitkan" --data-urlencode "_csrf=$tok" --data-urlencode "class_id=2"
tok=$(curl -s -b "$CJ" "$BASE/admin/perangkat" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -o /dev/null -b "$CJ" -c "$CJ" -X POST "$BASE/admin/perangkat/baru" --data-urlencode "_csrf=$tok" --data-urlencode "name=Tablet E2E" --data-urlencode "room_id=1"
# Pastikan ada sesi yang berlaku sekarang apa pun jam/harinya: sesi tambahan kelas X-1 di ruangan 1 (jam kosong/hari libur tetap bisa diuji).
tok=$(curl -s -b "$CJ" "$BASE/admin/sesi" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -o /dev/null -b "$CJ" -c "$CJ" -X POST "$BASE/admin/sesi/baru" --data-urlencode "_csrf=$tok" --data-urlencode "class_id=1" --data-urlencode "room_id=1" \
  --data-urlencode "date=$(php -r 'date_default_timezone_set("Asia/Makassar"); echo date("Y-m-d");')" --data-urlencode "start=$(php -r 'date_default_timezone_set("Asia/Makassar"); echo date("H:i", time() - 1200);')" --data-urlencode "end=$(php -r 'date_default_timezone_set("Asia/Makassar"); echo date("H:i", min(time() + 5400, strtotime("23:59")));')" --data-urlencode "note=E2E"
read -r PAIR CODES WRONG < <(php -r '
$p = new PDO("sqlite:" . $argv[1]);
$pair = $p->query("SELECT pair_code FROM devices ORDER BY id DESC LIMIT 1")->fetchColumn();
$codes = $p->query("SELECT c.code FROM cards c JOIN class_members m ON m.student_id = c.student_id WHERE m.class_id = 1 AND c.status = \"active\" ORDER BY c.student_id")->fetchAll(PDO::FETCH_COLUMN);
$wrong = $p->query("SELECT c.code FROM cards c JOIN class_members m ON m.student_id = c.student_id WHERE m.class_id = 2 AND c.status = \"active\" LIMIT 1")->fetchColumn();
echo "$pair " . implode(",", $codes) . " $wrong\n";' "$TMP/e2e.sqlite")
echo "Perangkat: kode pasangan $PAIR; $(echo "$CODES" | tr ',' '\n' | wc -l) kartu X-1"
BASE="$BASE" PAIR_CODE="$PAIR" CODES="$CODES" WRONG_CODE="$WRONG" SHOTS="${SHOTS:-}" node "$ROOT/tests/e2e/kiosk.js"
rc=$?
if grep -iE 'PHP (Fatal|Warning|Parse|Deprecated)|Uncaught' "$TMP/server.log" | grep -q "$ROOT/"; then
  echo "Server log berisi error PHP:"; grep -iE 'PHP (Fatal|Warning|Parse|Deprecated)|Uncaught' "$TMP/server.log" | grep "$ROOT/" | head; exit 1
fi
exit $rc
