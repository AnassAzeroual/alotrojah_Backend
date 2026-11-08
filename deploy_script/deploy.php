<?php

declare(strict_types=1);

/**
 * Server-side deploy extractor for the native PHP v2 backend.
 *
 * Upload to api/public/deploy.php, then call once:
 *   https://api.alotrojah.ma/deploy.php?token=YOUR_DEPLOY_TOKEN&action=seed
 *
 * 1. Authenticate with DEPLOY_TOKEN.
 * 2. Purge the old release, preserving storage/uploads (user files) and
 *    storage/logs (production logs).
 * 3. Extract the incoming backend.zip over the release.
 * 4. Optional `&action=seed`: create the first admin if missing (the v2
 *    equivalent of the old Laravel `migrate` + `db:seed` action).
 * 5. Recreate runtime directories, reset opcache, remove the zip and self-delete.
 */

define('DEPLOY_TOKEN', '__DEPLOY_TOKEN__');

header('Content-Type: application/json');

$token = (string) ($_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? $_GET['token'] ?? '');
if (! hash_equals(DEPLOY_TOKEN, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}
if (! class_exists('ZipArchive')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'ziparchive-missing']);
    exit;
}

set_time_limit(600);

$dest = dirname(__DIR__);          // api/
$newZip = $dest.'/backend.zip';    // incoming release

if (! file_exists($newZip)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'backend.zip-missing']);
    exit;
}

// One deploy at a time. The lock lives outside the extracted release.
$lockPath = $dest.'/storage/deploy.lock';
@mkdir(dirname($lockPath), 0755, true);
$deployLock = @fopen($lockPath, 'c');
if ($deployLock === false || ! flock($deployLock, LOCK_EX | LOCK_NB)) {
    http_response_code(423);
    echo json_encode(['ok' => false, 'error' => 'deploy-already-running']);
    exit;
}

/** Delete a path without following symlinks. */
function wipe_path(string $path): void
{
    if (is_link($path)) {
        @unlink($path);

        return;
    }
    if (is_file($path)) {
        @unlink($path);

        return;
    }
    if (! is_dir($path)) {
        return;
    }
    foreach (@scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        wipe_path($path.'/'.$item);
    }
    @rmdir($path);
}

/**
 * Snapshot the outgoing release before it is wiped. Runtime data is excluded;
 * keep the five newest snapshots for a manual rollback.
 */
function backup_release(string $dest): ?string
{
    $dir = $dest.'/storage/backups';
    if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
        return null;
    }
    $rel = 'storage/backups/release-'.date('Ymd-His').'.zip';
    $path = $dest.'/'.$rel;
    $zip = new ZipArchive;
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return null;
    }

    $skipPrefixes = [
        'storage/uploads/',
        'storage/logs/',
        'storage/cache/',
        'storage/backups/',
    ];
    $skipExact = ['backend.zip', 'storage/deploy.lock', 'public/deploy.php', 'deploy_script/deploy.php'];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($files as $file) {
        $name = str_replace('\\', '/', substr($file->getPathname(), strlen($dest) + 1));
        if ($name === '' || $file->isDir() || in_array($name, $skipExact, true)) {
            continue;
        }
        foreach ($skipPrefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                continue 2;
            }
        }
        $zip->addFile($file->getPathname(), $name);
    }
    $zip->close();

    $backups = glob($dir.'/release-*.zip') ?: [];
    sort($backups);
    while (count($backups) > 5) {
        @unlink(array_shift($backups));
    }

    return $rel;
}

$backup = backup_release($dest);
if ($backup === null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'backup-failed']);
    exit;
}

// 1. Preserve uploads, logs, backups and the deploy lock; replace the rest.
foreach (@scandir($dest) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    if ($entry === basename($newZip)) {
        continue;
    }
    $full = $dest.'/'.$entry;

    if ($entry === 'storage' && ! is_link($full) && is_dir($full)) {
        foreach (@scandir($full) ?: [] as $sub) {
            if ($sub === '.' || $sub === '..' || $sub === 'uploads' || $sub === 'logs' || $sub === 'backups' || $sub === 'deploy.lock') {
                continue;
            }
            wipe_path($full.'/'.$sub);
        }

        continue;
    }

    wipe_path($full);
}

// 2. Extract the release.
$zip = new ZipArchive;
if ($zip->open($newZip) !== true) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'zip-open-failed']);
    exit;
}
$count = $zip->numFiles;
for ($i = 0; $i < $count; $i++) {
    $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
    if ($name === '' || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) || preg_match('#(^|/)\.\.(/|$)#', $name)) {
        $zip->close();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'unsafe-zip-entry']);
        exit;
    }
}
if (! $zip->extractTo($dest)) {
    $zip->close();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'extract-failed']);
    exit;
}
$zip->close();

$action = (string) ($_GET['action'] ?? 'deploy');
$shouldMigrate = $action === 'migrate' || ($_POST['migrate'] ?? '') === '1';
$shouldSeed = in_array($action, ['seed', 'migrate'], true) || ($_POST['seed'] ?? '') === '1';
$migrationLog = '(not requested)';
$migrationOk = null;
$referenceLog = '(not requested)';
$referenceOk = null;
$referenceAudit = null;
$seedLog = '(not requested)';
$seedOk = null;

spl_autoload_register(static function (string $class) use ($dest): void {
    $prefix = 'App\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $file = $dest.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// 3. Optional schema migrations (up-only SQL; checksummed in schema_migrations).
if ($shouldMigrate) {
    try {
        \App\Core\Config::load($dest.'/.env');
        $migrator = new \App\Core\Migrator($dest.'/database/migrations', \App\Core\Database::getPdo());
        $result = $migrator->run();
        $migrationOk = true;
        $migrationLog = $result['applied'] === []
            ? "no pending migrations ({$result['skipped']} skipped)"
            : 'applied: '.implode(', ', $result['applied']);
    } catch (Throwable $e) {
        $migrationOk = false;
        $migrationLog = get_class($e).': '.$e->getMessage();
    }
}

// 3b. Baseline scoring modules are reference data, not demo data. They must
//     exist for scoring/workspace/report screens on a fresh production DB.
if ($migrationOk !== false) {
    try {
        \App\Core\Config::load($dest.'/.env');
        $referenceCreated = \App\Core\ReferenceData::seedScoringModules();
        $referenceAudit = \App\Core\ReferenceData::audit();
        $referenceOk = $referenceAudit['ok'];
        $referenceLog = "scoring modules created: {$referenceCreated}; "
            ."quran_verses: {$referenceAudit['quran_verses']}; "
            .'missing_tables: '.(implode(',', $referenceAudit['missing_tables']) ?: 'none').'; '
            .'missing_columns: '.(implode(',', $referenceAudit['missing_columns']) ?: 'none').'; '
            .'missing_modules: '.(implode(',', $referenceAudit['missing_modules']) ?: 'none');
    } catch (Throwable $e) {
        $referenceOk = false;
        $referenceLog = get_class($e).': '.$e->getMessage();
    }
}

// 4. Optional first-admin seed. Same one-shot extractor flow as the old
//    Laravel `action=migrate` (which also ran db:seed), but native v2 has no
//    Artisan: boot its autoloader, hash with Security::hashPassword() and
//    insert through Database.
if ($shouldSeed && $migrationOk === false) {
    $seedLog = 'skipped: migration failed';
}

if ($shouldSeed && $migrationOk !== false) {
    try {
        \App\Core\Config::load($dest.'/.env');

        $adminEmail = strtolower(trim((string) ($_POST['admin_email'] ?? $_GET['admin_email'] ?? 'admin@example.org')));
        $adminPassword = (string) ($_POST['admin_password'] ?? 'password123');
        $adminName = trim((string) ($_POST['admin_name'] ?? 'Admin'));

        if (! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('invalid admin email');
        }
        if (strlen($adminPassword) < 6) {
            throw new RuntimeException('admin password too short');
        }

        $existing = \App\Core\Database::fetchOne(
            'SELECT id, role FROM `users` WHERE email = :email LIMIT 1',
            ['email' => $adminEmail],
        );
        if ($existing !== null) {
            $seedLog = "admin already exists (id={$existing['id']}); no change";
            $seedOk = true;
        } else {
            $id = \App\Core\Database::insert('users', [
                'full_name' => $adminName,
                'email' => $adminEmail,
                'password_hash' => \App\Core\Security::hashPassword($adminPassword),
                'role' => 'admin',
                'teacher_type' => null,
                'is_active' => 1,
            ]);
            $seedLog = "created admin id={$id}";
            $seedOk = true;
        }
    } catch (Throwable $e) {
        $seedOk = false;
        $seedLog = get_class($e).': '.$e->getMessage();
    }
}

// 4. Runtime directories + opcache.
foreach ([
    $dest.'/storage/uploads',
    $dest.'/storage/logs',
    $dest.'/storage/cache',
] as $dir) {
    @mkdir($dir, 0755, true);
}
if (function_exists('opcache_reset')) {
    @opcache_reset();
}

@unlink($newZip);
// Do not leave the token-bearing extractor on the public host.
@unlink(__FILE__);
$extractorGone = ! file_exists(__FILE__);

$ok = $extractorGone
    && ($migrationOk === null || $migrationOk)
    && ($referenceOk === null || $referenceOk)
    && ($seedOk === null || $seedOk);
if (! $ok) {
    http_response_code(500);
}

echo json_encode([
    'ok' => $ok,
    'files' => $count,
    'backup' => $backup,
    'migrations' => $migrationLog,
    'migrations_ok' => $migrationOk,
    'reference' => $referenceLog,
    'reference_ok' => $referenceOk,
    'reference_audit' => $referenceAudit,
    'seed' => $seedLog,
    'seed_ok' => $seedOk,
    'extractor_deleted' => $extractorGone,
    'storage_uploads' => 'preserved',
    'storage_logs' => 'preserved',
]);
