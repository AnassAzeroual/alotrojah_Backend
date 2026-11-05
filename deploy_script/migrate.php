<?php

declare(strict_types=1);

/**
 * Apply pending SQL migrations.
 *
 * Usage:
 *   php deploy_script/migrate.php
 *   php deploy_script/migrate.php --status
 *
 * Files: database/migrations/YYYYMMDDHHMMSS_name.sql
 */

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $file = BASE_PATH.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;

Config::load(BASE_PATH.'/.env');

$migrator = new Migrator(BASE_PATH.'/database/migrations', Database::getPdo());

try {
    if (in_array('--status', $argv, true)) {
        $status = $migrator->status();
        echo 'Applied: '.count($status['applied']).PHP_EOL;
        echo 'Pending: '.count($status['pending']).PHP_EOL;
        foreach ($status['pending'] as $version) {
            echo "  {$version}".PHP_EOL;
        }
        exit(0);
    }

    $result = $migrator->run();
    foreach ($result['applied'] as $version) {
        echo "Applied {$version}".PHP_EOL;
    }
    echo "Skipped {$result['skipped']} already-applied migration(s).".PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}
