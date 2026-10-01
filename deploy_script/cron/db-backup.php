<?php
declare(strict_types=1);
// Host-side DB backup. Runs via panel cron (CLI only), NOT via web.
// Install once via FileZilla to /home/alotr15q/cron/db-backup.php
// (OUTSIDE api/ so deploys never wipe it). Dumps go to /home/alotr15q/backups/.
// Panel cron example (weekly + daily in exam season):
//   php /home/alotr15q/cron/db-backup.php >> /home/alotr15q/cron/backup.log 2>&1

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('cli only'); }
set_time_limit(600);

$API_DIR     = '/home/alotr15q/api';
$BACKUP_DIR  = '/home/alotr15q/backups';
$KEEP_NEWEST = 4;

function fail(string $msg): void { fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] FAIL: $msg\n"); exit(1); }
function info(string $msg): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] $msg\n"); }

// --- read DB creds from the deployed .env (single source of truth) ---
$envFile = $API_DIR . '/.env';
if (!is_file($envFile)) fail(".env missing at $envFile (deploy first)");
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
    $env[trim($k)] = trim($v, " \t\"'");
}
foreach (['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $k) {
    if (empty($env[$k])) fail("$k missing in .env");
}
$host = $env['DB_HOST'] ?? 'localhost';
$port = $env['DB_PORT'] ?? '3306';

@mkdir($BACKUP_DIR, 0755, true);
if (!is_dir($BACKUP_DIR)) fail("cannot create $BACKUP_DIR");

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname={$env['DB_DATABASE']};charset=utf8mb4",
        $env['DB_USERNAME'], $env['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]
    );
} catch (Throwable $e) { fail('connect: ' . $e->getMessage()); }

$ts   = date('Ymd-His');
$file = "$BACKUP_DIR/quran_prod-$ts.sql";
$fh   = fopen($file, 'wb') ?: fail("cannot write $file");

$w = static function (string $s) use ($fh): void { fwrite($fh, $s); };
$w("-- AlOtrojah prod backup $ts | db={$env['DB_DATABASE']}\nSET FOREIGN_KEY_CHECKS=0;\n\n");

$tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);
$views  = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'VIEW\'')->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $t) {
    $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
    $w("DROP TABLE IF EXISTS `$t`;\n" . $create['Create Table'] . ";\n\n");
    $rows = $pdo->query("SELECT * FROM `$t`");
    $cols = [];
    for ($i = 0; $i < $rows->columnCount(); $i++) { $m = $rows->getColumnMeta($i); $cols[] = "`{$m['name']}`"; }
    $cl = implode(',', $cols);
    $batch = [];
    $flush = static function () use (&$batch, $t, $cl, $w): void {
        if ($batch) { $w("INSERT INTO `$t` ($cl) VALUES\n" . implode(",\n", $batch) . ";\n"); $batch = []; }
    };
    foreach ($rows as $r) {
        $vals = [];
        foreach (array_values($r) as $v) {
            if ($v === null) { $vals[] = 'NULL'; continue; }
            if (is_int($v) || is_float($v)) { $vals[] = (string)$v; continue; }
            $vals[] = "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
        }
        $batch[] = '(' . implode(',', $vals) . ')';
        if (count($batch) >= 500) $flush();
    }
    $flush();
    $w("\n");
    info("table $t dumped");
}
foreach ($views as $v) {
    $create = $pdo->query("SHOW CREATE VIEW `$v`")->fetch(PDO::FETCH_ASSOC);
    $w("DROP VIEW IF EXISTS `$v`;\n" . $create['Create View'] . ";\n\n");
}
$w("SET FOREIGN_KEY_CHECKS=1;\n");
fclose($fh);

// --- verify: non-empty + re-parseable header + table count sane ---
$size = filesize($file);
$content = file_get_contents($file, false, null, 0, 200000);
if ($size < 1024 || !str_contains($content, 'CREATE TABLE') || substr_count($content, 'CREATE TABLE') < count($tables)) {
    @unlink($file);
    fail("backup verify failed (size=$size), deleted");
}

// --- retention: keep newest N dumps ---
$files = glob("$BACKUP_DIR/quran_prod-*.sql") ?: [];
rsort($files);
foreach (array_slice($files, $KEEP_NEWEST) as $old) { @unlink($old); info('pruned ' . basename($old)); }

info("OK $file (" . round($size / 1024, 1) . " KB, " . count($tables) . " tables, " . count($views) . " views)");
