<?php

declare(strict_types=1);

/**
 * Integration smoke for the group-scoped session generator.
 * Uses a transaction and always rolls back; safe for a local/dev database.
 *
 * Run: php deploy_script/test-session-generator.php
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
use App\Core\SessionGenerator;

Config::load(BASE_PATH.'/.env');

$season = Database::fetchOne(
    'SELECT id, center_id FROM `academic_seasons` WHERE is_current = 1 ORDER BY center_id IS NULL ASC LIMIT 1',
);
if (! $season) {
    fwrite(STDERR, "No current season found.\n");
    exit(1);
}

$centerId = (int) ($season['center_id'] ?? 1);
$teacherId = (int) Database::fetchValue(
    'SELECT id FROM `users` WHERE role = :role AND center_id = :center ORDER BY id LIMIT 1',
    ['role' => 'teacher', 'center' => $centerId],
);
if ($teacherId === 0) {
    fwrite(STDERR, "No teacher found for the current season center.\n");
    exit(1);
}

$pdo = Database::getPdo();
$pdo->beginTransaction();
try {
    $groupId = Database::insert('groups', [
        'center_id' => $centerId,
        'teacher_id' => $teacherId,
        'name' => '__session_generator_test__',
        'capacity' => 20,
        'schedule_days' => 'Mon,Wed,Fri',
        'is_active' => 1,
    ]);

    $first = SessionGenerator::forGroup($groupId, (int) $season['id']);
    $second = SessionGenerator::forGroup($groupId, (int) $season['id']);
    $count = (int) Database::fetchValue(
        'SELECT COUNT(*) FROM `sessions` WHERE group_id = :gid AND season_id = :sid',
        ['gid' => $groupId, 'sid' => (int) $season['id']],
    );

    $pass = $first > 0 && $second === 0 && $count === $first;
    printf(
        "%s session generator: first=%d second=%d count=%d\n",
        $pass ? 'PASS' : 'FAIL',
        $first,
        $second,
        $count,
    );
    $pdo->rollBack();
    exit($pass ? 0 : 1);
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'FAIL session generator: '.$e->getMessage()."\n");
    exit(1);
}
