#!/usr/bin/env bash
# Uji performa Fase 4: server PHP (beberapa worker) + SQLite + data demo + data uji (tests/perf/seed.php), lalu
#   1. kiosk: latensi N scan USB, jaringan lambat, 36 scan beruntun, offline 500 scan + muat ulang di tengah (Playwright)
#   2. batch yang sama dikirim 3 kali (curl)
#   3. beban: tahap laju tetap ke POST /api/scan (node tests/perf/load.js)
# Hasil mentah (JSON) ditulis ke $OUT_DIR. Butuh: php, node, playwright + Chromium, curl.
# Variabel: PORT, WORKERS (default 8), N_LATENCY (1000), N_OFFLINE (500), STAGES ("20:60 50:30 100:30 200:20 400:15"), OUT_DIR.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PORT="${PORT:-8767}"
BASE="http://127.0.0.1:$PORT"
WORKERS="${WORKERS:-8}"
OUT_DIR="${OUT_DIR:-$ROOT/storage/perf}"
TMP="$(mktemp -d)"
DB="$TMP/perf.sqlite"
trap 'kill "${PID:-0}" 2>/dev/null || true; rm -f "$ROOT/config/config.php"; rm -rf "$TMP"' EXIT
mkdir -p "$OUT_DIR" "$ROOT/storage/logs"
cd "$ROOT"
cp config/config.example.php config/config.php
sed -i "s/'driver' *=> *'mysql'/'driver' => 'sqlite'/" config/config.php
sed -i "s#'sqlite_path' *=> *[^,]*,#'sqlite_path' => '$DB',#" config/config.php

serve() { PHP_CLI_SERVER_WORKERS="$1" php -S "127.0.0.1:$PORT" -t public public/index.php > "$TMP/server.log" 2>&1 & PID=$!
  for _ in $(seq 1 50); do curl -s -o /dev/null "$BASE/masuk" && break; sleep 0.2; done; }
q() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA busy_timeout = 5000"); echo json_encode($p->query($argv[2])->fetchAll(PDO::FETCH_ASSOC)), "\n";' "$DB" "$1"; }

serve 1
CJ="$TMP/cookies"
TOKEN=$(curl -s -c "$CJ" -b "$CJ" "$BASE/install" | grep -o 'name="_csrf" value="[^"]*"' | cut -d'"' -f4)
curl -s -o /dev/null -c "$CJ" -b "$CJ" -X POST "$BASE/install" --data-urlencode "_csrf=$TOKEN" --data-urlencode "name=Super Admin" \
  --data-urlencode "username=superadmin" --data-urlencode "password=PerfTest12345" --data-urlencode "password_confirm=PerfTest12345" --data-urlencode "demo=1"
php tests/perf/seed.php "$DB" > "$TMP/seed.json"
kill "$PID"; wait "$PID" 2>/dev/null || true
serve "$WORKERS"
echo "Server: php -S, $WORKERS worker, SQLite (WAL). $(php -v | head -1)"

kiosk() { MODE="$1" N="$2" OFFSET="$3" BASE="$BASE" SEED="$TMP/seed.json" STATE="$TMP/state.json" node tests/perf/kiosk.js | tee "$OUT_DIR/kiosk-$1.json"; }
N_LAT="${N_LATENCY:-1000}"; N_OFF="${N_OFFLINE:-500}"

echo "== 1a. Latensi $N_LAT scan USB (2 putaran kartu: Hadir lalu duplikat) =="
kiosk latency "$N_LAT" 0
q "DELETE FROM attendances WHERE session_id = (SELECT id FROM sessions WHERE note = 'Uji performa' ORDER BY id LIMIT 1)" > /dev/null
echo "== 1b. Jaringan lambat (RTT 300 ms) 60 scan =="
kiosk slow 60 0
echo "== 1c. 36 scan beruntun =="
kiosk queue36 36 100
q "DELETE FROM attendances WHERE session_id = (SELECT id FROM sessions WHERE note = 'Uji performa' ORDER BY id LIMIT 1)" > /dev/null
echo "== 1d. Offline $N_OFF scan, muat ulang di tengah, sambung lagi =="
kiosk offline "$N_OFF" 0
php -r '
date_default_timezone_set("Asia/Makassar");
$p = new PDO("sqlite:" . $argv[1]);
$r = json_decode(file_get_contents($argv[2]), true);
$sid = (int) $p->query("SELECT id FROM sessions WHERE note = \"Uji performa\" ORDER BY id LIMIT 1")->fetchColumn();
$n = (int) $p->query("SELECT COUNT(*) FROM attendances WHERE session_id = $sid")->fetchColumn();
$d = (int) $p->query("SELECT COUNT(*) FROM (SELECT student_id FROM attendances WHERE session_id = $sid GROUP BY student_id HAVING COUNT(*) > 1)")->fetchColumn();
$from = date("Y-m-d H:i:s", intdiv($r["offline_mulai"], 1000) - 2); $to = date("Y-m-d H:i:s", intdiv($r["online_lagi"], 1000) + 2);
$in = (int) $p->query("SELECT COUNT(*) FROM attendances WHERE session_id = $sid AND scanned_at BETWEEN \"$from\" AND \"$to\"")->fetchColumn();
$out = ["tercatat" => $n, "ganda" => $d, "waktu_scan_dalam_jendela_offline" => $in, "jendela" => [$from, $to]];
echo json_encode($out), "\n"; file_put_contents($argv[3], json_encode($out));
exit($n === (int) $r["n"] && $d === 0 && $in === $n ? 0 : 1);' "$DB" "$OUT_DIR/kiosk-offline.json" "$OUT_DIR/kiosk-offline-db.json"

echo "== 2. Batch yang sama 3 kali =="
DEV=$(php -r '$s = json_decode(file_get_contents($argv[1]), true); echo $s["load"]["tokens"][0];' "$TMP/seed.json")
php -r '$s = json_decode(file_get_contents($argv[1]), true); $items = []; date_default_timezone_set("Asia/Makassar");
foreach (array_slice($s["load"]["codes"], -50) as $i => $c) { $items[] = ["scan_id" => "batch3x-" . $i, "code" => $c, "scanned_at" => date("c", time() - 600 + $i)]; }
echo json_encode(["sent_at" => date("c"), "scans" => $items]);' "$TMP/seed.json" > "$TMP/batch.json"
for i in 1 2 3; do curl -s -X POST "$BASE/api/scan/batch" -H 'Content-Type: application/json' -H "X-Device-Token: $DEV" --data-binary "@$TMP/batch.json" \
  | php -r '$d = json_decode(stream_get_contents(STDIN), true); $c = []; foreach ($d["results"] ?? [] as $r) { $c[$r["state"]] = ($c[$r["state"]] ?? 0) + 1; } echo "  kirim ke-'$i': ", json_encode($c), "\n";'; done
q "SELECT COUNT(*) AS baris_kehadiran, COUNT(DISTINCT a.student_id) AS siswa, (SELECT COUNT(*) FROM scan_logs WHERE scan_id LIKE 'batch3x-%') AS log_scan FROM attendances a WHERE a.student_id IN (SELECT student_id FROM scan_logs WHERE scan_id LIKE 'batch3x-%')" | tee "$OUT_DIR/batch3x.json"

echo "== 3. Beban POST /api/scan =="
# shellcheck disable=SC2086
OUT="$OUT_DIR/load.json" node tests/perf/load.js "$TMP/seed.json" "$BASE" ${STAGES:-20:60 50:30 100:30 200:20 400:15}
q "SELECT COUNT(*) AS kehadiran, (SELECT COUNT(*) FROM scan_logs) AS log_scan, (SELECT COUNT(*) FROM scan_metrics) AS metrik FROM attendances" | tee "$OUT_DIR/db-akhir.json"
if grep -iE 'PHP (Fatal|Warning|Parse|Deprecated)|Uncaught' "$TMP/server.log" | grep -q "$ROOT/"; then
  echo "Server log berisi error PHP:"; grep -iE 'PHP (Fatal|Warning|Parse|Deprecated)|Uncaught' "$TMP/server.log" | grep "$ROOT/" | sort | uniq -c | head; exit 1
fi
if [ -n "${SHOT:-}" ]; then # tangkapan layar dashboard kecepatan dengan data uji
  BASE="$BASE" SHOT="$SHOT" node -e '
const { chromium } = require("playwright");
(async () => { const b = await chromium.launch(); const p = await b.newPage({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
  await p.goto(process.env.BASE + "/masuk"); await p.fill("[name=username]", "superadmin"); await p.fill("[name=password]", "PerfTest12345");
  await Promise.all([p.waitForNavigation(), p.click("button[type=submit]")]);
  await p.goto(process.env.BASE + "/admin/kecepatan"); await p.screenshot({ path: process.env.SHOT, fullPage: true }); await b.close(); })();'
fi
echo "Selesai. Hasil mentah: $OUT_DIR"
