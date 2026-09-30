<?php
declare(strict_types=1);
// Server-side deploy extractor with backup.
// Upload to api/public/deploy.php, then call once:
//   https://api.alotrojah.ma/deploy.php?token=YOUR_DEPLOY_TOKEN
//
// Flow:
//   1. Auth via token.
//   2. Zip EVERYTHING currently in api/ (incl. .env) into api/backup-<ts>.zip,
//      skipping the incoming backend.zip and any previous backup-*.zip.
//   3. Verify the backup is valid, then delete every old file/folder in api/
//      (leaving only the backup zip and the incoming backend.zip).
//   4. Extract the new backend.zip into api/.
//   5. Recreate runtime dirs, reset opcache, remove backend.zip, self-delete.
//
// Result: api/ = fresh Laravel app + one backup-<ts>.zip of the old version.

define('DEPLOY_TOKEN', '__DEPLOY_TOKEN__');
header('Content-Type: application/json');

$token = (string)($_GET['token'] ?? '');
if (!hash_equals(DEPLOY_TOKEN, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'ziparchive-missing']);
    exit;
}

set_time_limit(600);

$dest        = dirname(__DIR__);               // api/
$newZip      = $dest . '/backend.zip';         // incoming release
$backupName  = 'backup-' . date('Ymd-His') . '.zip';
$backupPath  = $dest . '/' . $backupName;      // backup lives INSIDE api/

if (!file_exists($newZip)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'backend.zip-missing']);
    exit;
}

// -------------------------------------------------------------------------- //
// 0. Delete any pre-existing zip in api/ FIRST (old backups etc.), except the
//    incoming backend.zip. This guarantees the backup we build next contains
//    only real Laravel files — never a previous backup — so it never grows/nests.
// -------------------------------------------------------------------------- //
$removedZips = [];
foreach (@scandir($dest) ?: [] as $entry) {
    if ($entry === basename($newZip)) {
        continue; // keep the new release
    }
    if (is_file($dest . '/' . $entry) && strtolower(substr($entry, -4)) === '.zip') {
        if (@unlink($dest . '/' . $entry)) {
            $removedZips[] = $entry;
        }
    }
}

// Names in api/ that must NOT be swept into the backup or deleted as "old".
$protected = [
    basename($newZip),   // backend.zip (the new release we still need)
    $backupName,         // the backup we are about to create
];

/**
 * Recursively add a directory's contents to a zip, relative to $baseLen.
 * Skips any top-level entry whose name is in $skipTop.
 */
function backup_add(ZipArchive $zip, string $dir, int $baseLen, array $skipTop): void
{
    $items = @scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $dir . '/' . $item;
        // Only skip protected names at the api/ top level.
        if ($skipTop && in_array($item, $skipTop, true)) {
            continue;
        }
        $rel = substr($full, $baseLen);
        if (is_dir($full)) {
            $zip->addEmptyDir($rel);
            backup_add($zip, $full, $baseLen, []); // skip list only applies at top
        } else {
            $zip->addFile($full, $rel);
        }
    }
}

/**
 * Recursively delete a directory's contents (and the dir entries themselves),
 * skipping protected top-level names. Does not remove $dir itself.
 */
function purge_dir(string $dir, array $skipTop): void
{
    $items = @scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if ($skipTop && in_array($item, $skipTop, true)) {
            continue;
        }
        $full = $dir . '/' . $item;
        if (is_dir($full)) {
            purge_dir($full, []);
            @rmdir($full);
        } else {
            @unlink($full);
        }
    }
}

// -------------------------------------------------------------------------- //
// 1. Build the backup zip of the current api/ contents.
//    We skip ONLY the incoming backend.zip and the backup we are creating right
//    now. Any OLD backup-*.zip from previous deploys IS included, so it ends up
//    nested inside the new backup and then gets deleted in the purge step.
//    Result: api/ keeps just ONE backup zip (the newest), old ones are gone.
// -------------------------------------------------------------------------- //
$skipForBackup = $protected; // [backend.zip, new backup name] only

$backup = new ZipArchive();
if ($backup->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'backup-open-failed']);
    exit;
}
backup_add($backup, $dest, strlen($dest) + 1, $skipForBackup);
$backupCount = $backup->numFiles;
$backup->close();

// Verify the backup is real before we delete anything.
$verify = new ZipArchive();
if ($verify->open($backupPath) !== true || $verify->numFiles === 0) {
    if ($verify) { @$verify->close(); }
    @unlink($backupPath);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'backup-verify-failed']);
    exit;
}
$verify->close();

// -------------------------------------------------------------------------- //
// 2. Delete ALL old files/folders in api/ — including any previous
//    backup-*.zip (now safely nested inside the new backup) — keeping only the
//    new backup zip and the incoming backend.zip.
// -------------------------------------------------------------------------- //
purge_dir($dest, $protected);

// -------------------------------------------------------------------------- //
// 3. Extract the new release into the now-clean api/.
// -------------------------------------------------------------------------- //
$zip = new ZipArchive();
if ($zip->open($newZip) !== true) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'zip-open-failed', 'backup' => $backupName]);
    exit;
}
$count = $zip->numFiles;
if (!$zip->extractTo($dest)) {
    $zip->close();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'extract-failed', 'backup' => $backupName]);
    exit;
}
$zip->close();

// -------------------------------------------------------------------------- //
// 4. Recreate runtime dirs, reset opcache, clean up.
// -------------------------------------------------------------------------- //
@mkdir($dest . '/storage/framework/cache/data', 0755, true);
@mkdir($dest . '/storage/framework/sessions', 0755, true);
@mkdir($dest . '/storage/framework/views', 0755, true);
@mkdir($dest . '/bootstrap/cache', 0755, true);
if (function_exists('opcache_reset')) { @opcache_reset(); }

@unlink($newZip);
// Self-delete so the token-bearing extractor does not linger publicly.
@unlink(__FILE__);

echo json_encode([
    'ok'            => true,
    'files'         => $count,
    'backup'        => $backupName,
    'backup_files'  => $backupCount,
    'removed_zips'  => $removedZips,
]);
