<?php
/**
 * Data uji performa (Fase 4), langsung ke SQLite setelah instalasi + data demo:
 *   - ruangan R-PERF + kelas PERF-500 (500 siswa berkartu) + sesi tambahan sepanjang hari → uji kiosk (latensi, offline)
 *   - ruangan R-LOAD + kelas LOAD-N (default 6000 siswa) + sesi sepanjang hari + 30 perangkat → uji beban
 * Pakai: php tests/perf/seed.php <file.sqlite> [jumlah siswa beban]   → mencetak JSON {kiosk:{pair,codes}, load:{tokens,codes}}
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Makassar');
$pdo = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA journal_mode = WAL');
$nLoad = (int) ($argv[2] ?? 6000);
$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$year = (int) $pdo->query('SELECT id FROM academic_years WHERE is_active = 1')->fetchColumn();
$alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
$code = static function () use ($alphabet): string {
    $c = '';
    for ($i = 0; $i < 12; $i++) {
        $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $c;
};
$ins = static function (string $table, array $d) use ($pdo): int {
    $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($d)) . ') VALUES (' . implode(',', array_fill(0, count($d), '?')) . ')')->execute(array_values($d));
    return (int) $pdo->lastInsertId();
};
$make = static function (string $roomCode, string $className, int $n, int $nisBase) use ($pdo, $ins, $code, $year, $now, $today): array {
    $room = $ins('rooms', ['code' => $roomCode, 'name' => 'Ruang uji ' . $roomCode, 'created_at' => $now]);
    $class = $ins('classes', ['academic_year_id' => $year, 'name' => $className, 'grade' => 10, 'room_id' => $room, 'created_at' => $now]);
    $codes = [];
    for ($i = 1; $i <= $n; $i++) {
        $sid = $ins('students', ['nis' => (string) ($nisBase + $i), 'full_name' => 'Siswa Uji ' . $className . ' ' . $i, 'gender' => $i % 2 ? 'L' : 'P', 'status' => 'Aktif', 'created_at' => $now, 'updated_at' => $now]);
        $ins('class_members', ['class_id' => $class, 'student_id' => $sid, 'academic_year_id' => $year, 'created_at' => $now]);
        $codes[] = $c = $code();
        $ins('cards', ['student_id' => $sid, 'code' => $c, 'version' => 1, 'status' => 'active', 'issued_at' => $now]);
    }
    // Sesi tambahan sepanjang hari; late_after di akhir hari sehingga semua scan = Hadir
    $ins('sessions', ['slot_key' => null, 'class_id' => $class, 'room_id' => $room, 'session_date' => $today, 'scan_from' => $today . ' 00:00:00',
        'starts_at' => $today . ' 00:00:00', 'late_after' => $today . ' 23:59:59', 'ends_at' => $today . ' 23:59:59', 'kind' => 'tambahan', 'status' => 'open', 'note' => 'Uji performa', 'created_at' => $now]);
    return [$room, $codes];
};
$pdo->beginTransaction();
[$roomK, $codesK] = $make('R-PERF', 'PERF-500', 500, 900000);
[$roomL, $codesL] = $make('R-LOAD', 'LOAD-' . $nLoad, $nLoad, 800000);
$pair = '424242';
$ins('devices', ['name' => 'Kiosk uji performa', 'room_id' => $roomK, 'pair_code' => $pair, 'pair_expires_at' => date('Y-m-d H:i:s', time() + 86400), 'status' => 'pending', 'created_at' => $now]);
$tokens = [];
for ($i = 1; $i <= 30; $i++) {
    $tokens[] = $t = bin2hex(random_bytes(32));
    $ins('devices', ['name' => 'Perangkat beban ' . $i, 'room_id' => $roomL, 'token_hash' => hash('sha256', $t), 'status' => 'active', 'paired_at' => $now, 'created_at' => $now]);
}
$pdo->commit();
echo json_encode(['kiosk' => ['pair' => $pair, 'codes' => $codesK, 'room' => $roomK], 'load' => ['tokens' => $tokens, 'codes' => $codesL]]);
