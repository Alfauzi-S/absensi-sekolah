<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;
use App\Core\Migrator;
use App\Models\Setting;

/**
 * Cadangan dan pemulihan (PRD Fase 7: cadangan database harian; pindah server = backup + restore, < 30 menit).
 *
 * Satu file ZIP berisi:
 *   manifest.json      versi, driver asal, migrasi, jumlah baris per tabel, daftar file
 *   data/<tabel>.jsonl satu baris JSON per baris tabel (netral terhadap driver: cadangan SQLite bisa dipulihkan ke MariaDB dan sebaliknya)
 *   files/photos/…     foto siswa
 *   files/leaves/…     lampiran surat izin/sakit
 * Pemulihan: buat skema terbaru di database tujuan, masukkan baris (kolom yang tidak dikenal diabaikan), salin file, lalu cocokkan jumlah baris.
 */
final class Backup
{
    public const FORMAT = 1;
    public const KEEP = 14;                         // cadangan harian yang disimpan
    public const SKIP_TABLES = ['rate_limits'];     // data sementara, tidak perlu dicadangkan
    public const FILE_DIRS = ['photos', 'leaves'];  // folder di storage/ yang ikut dicadangkan
    private const CHUNK = 2000;

    /** Folder storage (bisa diganti untuk pengujian). */
    public static ?string $storage = null;

    public static function storage(): string
    {
        return self::$storage ?? ROOT_PATH . '/storage';
    }

    public static function dir(): string
    {
        $d = self::storage() . '/backups';
        if (!is_dir($d)) {
            mkdir($d, 0770, true);
        }
        return $d;
    }

    /** @return array<int, array{name:string, path:string, size:int, time:int}> terbaru dulu */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/absensi-*.zip') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'path' => $f, 'size' => (int) filesize($f), 'time' => (int) filemtime($f)];
        }
        usort($out, static fn ($a, $b) => $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']));
        return $out;
    }

    public static function latest(): ?array
    {
        return self::list()[0] ?? null;
    }

    /** Lokasi file cadangan dari namanya (hanya nama yang dibuat aplikasi ini). */
    public static function path(string $name): ?string
    {
        if (!preg_match('/^absensi-\d{8}-\d{6}(-[a-z0-9]+)?\.zip$/', $name)) {
            return null;
        }
        $p = self::dir() . '/' . $name;
        return is_file($p) ? $p : null;
    }

    /**
     * Buat cadangan. @return array{path:string, tables:int, rows:int, files:int, seconds:float, size:int}
     */
    public static function create(string $tag = ''): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Ekstensi PHP zip dibutuhkan untuk membuat cadangan.');
        }
        $t0 = microtime(true);
        $name = 'absensi-' . date('Ymd-His') . ($tag !== '' ? '-' . preg_replace('/[^a-z0-9]/', '', strtolower($tag)) : '') . '.zip';
        $final = self::dir() . '/' . $name;
        $tmp = $final . '.part';
        $work = sys_get_temp_dir() . '/absensi-backup-' . bin2hex(random_bytes(4));
        mkdir($work, 0700, true);
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Tidak bisa menulis ' . $tmp);
        }
        $counts = [];
        $rows = 0;
        try {
            foreach (DB::tables() as $table) {
                if (in_array($table, self::SKIP_TABLES, true)) {
                    continue;
                }
                $file = $work . '/' . $table . '.jsonl';
                $counts[$table] = self::dumpTable($table, $file);
                $rows += $counts[$table];
                $zip->addFile($file, 'data/' . $table . '.jsonl');
            }
            $files = 0;
            foreach (self::FILE_DIRS as $d) {
                $base = self::storage() . '/' . $d;
                if (!is_dir($base)) {
                    continue;
                }
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile()) {
                        $zip->addFile($f->getPathname(), 'files/' . $d . '/' . substr($f->getPathname(), strlen($base) + 1));
                        $files++;
                    }
                }
            }
            $manifest = [
                'format' => self::FORMAT, 'app' => 'absensi-sekolah', 'created_at' => now(), 'timezone' => date_default_timezone_get(),
                'driver' => DB::driver(), 'school' => Setting::get('school_name'), 'migrations' => Migrator::applied(),
                'tables' => $counts, 'rows' => $rows, 'files' => $files,
            ];
            $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (!$zip->close()) {
                throw new \RuntimeException('Gagal menutup file cadangan');
            }
            rename($tmp, $final);
            @chmod($final, 0640);
        } finally {
            foreach (glob($work . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($work);
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        Setting::set('backup_last_at', now());
        Setting::set('backup_last_file', $name);
        return ['path' => $final, 'tables' => count($counts), 'rows' => $rows, 'files' => $files, 'seconds' => round(microtime(true) - $t0, 2), 'size' => (int) filesize($final)];
    }

    /** Hapus cadangan lama, sisakan KEEP terbaru. @return int jumlah yang dihapus */
    public static function prune(int $keep = self::KEEP): int
    {
        $n = 0;
        foreach (array_slice(self::list(), max(1, $keep)) as $b) {
            if (@unlink($b['path'])) {
                $n++;
            }
        }
        return $n;
    }

    public static function manifest(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('File cadangan tidak bisa dibuka');
        }
        $m = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();
        if (!is_array($m) || ($m['app'] ?? '') !== 'absensi-sekolah' || (int) ($m['format'] ?? 0) !== self::FORMAT) {
            throw new \RuntimeException('Bukan file cadangan Absensi Sekolah yang dikenal');
        }
        return $m;
    }

    /**
     * Pulihkan ke database yang sedang dikonfigurasi. SEMUA data di database tujuan diganti.
     * @return array{tables:int, rows:int, files:int, seconds:float, mismatch:array<string,array{0:int,1:int}>}
     */
    public static function restore(string $zipPath, ?callable $progress = null): array
    {
        $t0 = microtime(true);
        $m = self::manifest($zipPath);
        $unknown = array_diff($m['migrations'] ?? [], array_keys(Migrator::files()));
        if ($unknown) {
            throw new \RuntimeException('Cadangan dibuat oleh versi aplikasi yang lebih baru (migrasi ' . implode(', ', $unknown) . '). Perbarui kode aplikasi dulu.');
        }
        $say = $progress ?? static function (string $s): void {};
        $zip = new \ZipArchive();
        $zip->open($zipPath);

        $say('Menyiapkan database kosong (' . DB::driver() . ')');
        self::emptyDatabase();
        DB::runFile(ROOT_PATH . '/database/schema.' . (DB::driver() === 'sqlite' ? 'sqlite' : 'mysql') . '.sql');
        Migrator::markAllApplied();
        $pdo = DB::pdo();
        DB::driver() === 'sqlite' ? $pdo->exec('PRAGMA foreign_keys = OFF') : $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $rows = 0;
        $mismatch = [];
        try {
            foreach ($m['tables'] as $table => $expected) {
                if ($table === 'schema_migrations' || !DB::tableExists($table)) {
                    continue;
                }
                $stream = $zip->getStream('data/' . $table . '.jsonl');
                if ($stream === false) {
                    throw new \RuntimeException('Data tabel ' . $table . ' tidak ada di cadangan');
                }
                DB::run('DELETE FROM ' . DB::ident($table));
                $n = self::loadTable($table, $stream);
                fclose($stream);
                $rows += $n;
                $say(sprintf('%-22s %8d baris', $table, $n));
                $actual = (int) DB::value('SELECT COUNT(*) FROM ' . DB::ident($table));
                if ($actual !== (int) $expected) {
                    $mismatch[$table] = [(int) $expected, $actual];
                }
            }
            // Migrasi yang tercatat di cadangan + semua migrasi kode (skema yang dibuat sudah versi terbaru)
            DB::run('DELETE FROM schema_migrations');
            Migrator::markAllApplied();
        } finally {
            DB::driver() === 'sqlite' ? $pdo->exec('PRAGMA foreign_keys = ON') : $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $files = 0;
        foreach (self::FILE_DIRS as $d) {
            $target = self::storage() . '/' . $d;
            if (is_dir($target)) {
                self::rrmdir($target);
            }
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (!preg_match('#^files/(' . implode('|', self::FILE_DIRS) . ')/([A-Za-z0-9._/-]+)$#', $entry, $mm) || str_contains($mm[2], '..')) {
                continue;
            }
            $dest = self::storage() . '/' . $mm[1] . '/' . $mm[2];
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0775, true);
            }
            file_put_contents($dest, (string) $zip->getFromIndex($i));
            $files++;
        }
        $zip->close();
        \App\Core\Cache::forgetPrefix('');
        Setting::forget();
        return ['tables' => count($m['tables']), 'rows' => $rows, 'files' => $files, 'seconds' => round(microtime(true) - $t0, 2), 'mismatch' => $mismatch];
    }

    private static function dumpTable(string $table, string $file): int
    {
        $fh = fopen($file, 'wb');
        $cols = DB::columns($table);
        $q = DB::ident($table);
        $n = 0;
        if (in_array('id', $cols, true)) {
            $last = 0;
            do {
                $batch = DB::all("SELECT * FROM $q WHERE id > " . $last . ' ORDER BY id LIMIT ' . self::CHUNK);
                foreach ($batch as $r) {
                    fwrite($fh, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                    $last = (int) $r['id'];
                    $n++;
                }
            } while (count($batch) === self::CHUNK);
        } else {
            foreach (DB::all("SELECT * FROM $q") as $r) {
                fwrite($fh, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                $n++;
            }
        }
        fclose($fh);
        return $n;
    }

    /** @param resource $stream */
    private static function loadTable(string $table, $stream): int
    {
        $known = array_flip(DB::columns($table));
        $pdo = DB::pdo();
        $n = 0;
        $batch = [];
        $cols = null;
        $flush = static function () use (&$batch, &$cols, $table, $pdo): void {
            if (!$batch) {
                return;
            }
            $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            $sql = 'INSERT INTO ' . DB::ident($table) . ' (' . implode(',', array_map([DB::class, 'ident'], $cols)) . ') VALUES ' . implode(',', array_fill(0, count($batch), $ph));
            $params = [];
            foreach ($batch as $r) {
                foreach ($cols as $c) {
                    $params[] = $r[$c] ?? null;
                }
            }
            $pdo->prepare($sql)->execute($params);
            $batch = [];
        };
        $pdo->beginTransaction();
        try {
            while (($line = fgets($stream)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $r = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
                $r = array_intersect_key($r, $known);
                $keys = array_keys($r);
                if ($cols !== null && $keys !== $cols) {
                    $flush();
                }
                $cols = $keys;
                $batch[] = $r;
                $n++;
                if (count($batch) >= max(1, intdiv(900, max(1, count($cols))))) {
                    $flush();
                }
            }
            $flush();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $n;
    }

    /** Hapus semua tabel aplikasi di database tujuan. */
    private static function emptyDatabase(): void
    {
        $pdo = DB::pdo();
        if (DB::driver() === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = OFF');
            foreach (DB::tables() as $t) {
                $pdo->exec('DROP TABLE IF EXISTS ' . DB::ident($t));
            }
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (DB::tables() as $t) {
                $pdo->exec('DROP TABLE IF EXISTS ' . DB::ident($t));
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        DB::disconnect();
    }

    private static function rrmdir(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
