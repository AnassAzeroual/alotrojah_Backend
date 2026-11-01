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

$token = (string) ($_GET['token'] ?? '');
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

// 1. Preserve only uploads + logs; everything else is replaced.
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
            if ($sub === '.' || $sub === '..' || $sub === 'uploads' || $sub === 'logs') {
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
if (! $zip->extractTo($dest)) {
    $zip->close();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'extract-failed']);
    exit;
}
$zip->close();

// 3. Optional first-admin seed. Same one-shot extractor flow as the old
//    Laravel `action=migrate` (which also ran db:seed), but native v2 has no
//    Artisan: boot its autoloader, hash with Security::hashPassword() and
//    insert through Database.
$action = (string) ($_GET['action'] ?? 'deploy');
$shouldSeed = in_array($action, ['seed', 'migrate'], true) || ($_POST['seed'] ?? '') === '1';
$seedLog = '(not requested)';
$seedOk = null;

if ($shouldSeed) {
    try {
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

$ok = $extractorGone && ($seedOk === null || $seedOk);
if (! $ok) {
    http_response_code(500);
}

echo json_encode([
    'ok' => $ok,
    'files' => $count,
    'seed' => $seedLog,
    'seed_ok' => $seedOk,
    'extractor_deleted' => $extractorGone,
    'storage_uploads' => 'preserved',
    'storage_logs' => 'preserved',
]);
