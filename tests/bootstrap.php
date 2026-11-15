<?php
declare(strict_types=1);

/**
 * Bootstrap pengujian: memuat aplikasi tanpa sesi/HTTP, dengan database SQLite sementara
 * yang dibuat dari schema + migrasi. Setiap proses tes memakai file database sendiri.
 */

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('BASE_PATH', '');
define('FULL_URL', 'http://localhost');
define('APP_LANG', 'id');

spl_autoload_register(static function (string $class): void {
    foreach (['App\\' => APP_PATH, 'Tests\\' => __DIR__] as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

require APP_PATH . '/helpers.php';

$dbFile = sys_get_temp_dir() . '/absensi-test-' . getmypid() . '.sqlite';
@unlink($dbFile);
register_shutdown_function(static fn () => @unlink($dbFile));

$GLOBALS['__config'] = [
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $dbFile],
    'timezone' => 'Asia/Makassar',
    'timezone_label' => 'WITA',
    'locale' => 'id',
    'debug' => true,
    'pretty_urls' => true,
    'base_url' => 'http://localhost',
    'session_name' => 'test',
];
date_default_timezone_set('Asia/Makassar');

\App\Core\Database::runFile(ROOT_PATH . '/database/schema.sqlite.sql');
\App\Core\Migrator::run();
