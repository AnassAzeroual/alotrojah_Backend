<?php
declare(strict_types=1);
// Server-side deploy extractor. No on-server backup.
// Upload to api/public/deploy.php, then call once:
//   https://api.alotrojah.ma/deploy.php?token=YOUR_DEPLOY_TOKEN
//
// Flow:
//   1. Auth via token.
//   2. Delete every old file/folder in api/, EXCEPT:
//      - backend.zip (the incoming release)
//      - storage/app/** (user uploads referenced by the DB — preserved)
//      Symlinks are unlinked, never followed (so public/storage can never
//      wipe storage/app/public through the link).
//   3. Extract the new backend.zip into api/. Files present in the zip
//      overwrite; files only on disk survive. storage/app therefore merges:
//      old uploads stay, new skeleton files (.gitignore) are added.
//   4. Recreate runtime dirs (framework cache/sessions/views, logs,
//      bootstrap/cache), reset opcache, remove backend.zip, self-delete.

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

$dest   = dirname(__DIR__);          // api/
$newZip = $dest . '/backend.zip';    // incoming release

if (!file_exists($newZip)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'backend.zip-missing']);
    exit;
}

/**
 * Delete a path without following symlinks: links are unlinked,
 * real dirs are emptied recursively then removed.
 */
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
    if (!is_dir($path)) {
        return;
    }
    foreach (@scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        wipe_path($path . '/' . $item);
    }
    @rmdir($path);
}

// -------------------------------------------------------------------------- //
// 1. Purge old release. Preserve backend.zip + storage/app (user uploads).
//    storage/framework/*, storage/logs/* and everything else is rebuilt.
// -------------------------------------------------------------------------- //
foreach (@scandir($dest) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    if ($entry === basename($newZip)) {
        continue; // incoming release
    }
    $full = $dest . '/' . $entry;
    if ($entry === 'storage' && !is_link($full) && is_dir($full)) {
        foreach (@scandir($full) ?: [] as $sub) {
            if ($sub === '.' || $sub === '..') {
                continue;
            }
            if ($sub === 'app' || $sub === 'logs') {
                continue; // user uploads + prod logs — keep, merge later
            }
            wipe_path($full . '/' . $sub);
        }
        continue;
    }
    wipe_path($full);
}

// -------------------------------------------------------------------------- //
// 2. Extract the new release. Overwrites tracked files; disk-only files
//    (uploads in storage/app) survive. Skeleton files merge in harmlessly.
// -------------------------------------------------------------------------- //
$zip = new ZipArchive();
if ($zip->open($newZip) !== true) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'zip-open-failed']);
    exit;
}
$count = $zip->numFiles;
if (!$zip->extractTo($dest)) {
    $zip->close();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'extract-failed']);
    exit;
}
$zip->close();

// -------------------------------------------------------------------------- //
// 3. Recreate runtime dirs, reset opcache, clean up.
// -------------------------------------------------------------------------- //
@mkdir($dest . '/storage/app/public', 0755, true);
@mkdir($dest . '/storage/framework/cache/data', 0755, true);
@mkdir($dest . '/storage/framework/sessions', 0755, true);
@mkdir($dest . '/storage/framework/views', 0755, true);
@mkdir($dest . '/storage/logs', 0755, true);
@mkdir($dest . '/bootstrap/cache', 0755, true);
if (function_exists('opcache_reset')) { @opcache_reset(); }

@unlink($newZip);
// Self-delete so the token-bearing extractor does not linger publicly.
@unlink(__FILE__);

echo json_encode(['ok' => true, 'files' => $count, 'storage_app' => 'preserved']);
