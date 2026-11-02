<?php

declare(strict_types=1);

/**
 * Build the production archive for the native PHP v2 backend.
 *
 * Usage: php deploy_script/Build-Zip.php [path-to-prod-env]
 *
 * The archive contains the application source plus the rendered production
 * `.env`; runtime data (uploads, logs, cache) is never shipped. The server-side
 * deploy.php preserves storage/uploads and storage/logs between releases.
 */
$appRoot = dirname(__DIR__);
$outZip = $appRoot.'/deploy_script/backend.zip';
$prodEnv = $argv[1] ?? ($appRoot.'/deploy_script/.env.production');

if (! file_exists($prodEnv)) {
    fwrite(STDERR, "Missing prod env: $prodEnv\n");
    exit(1);
}
if (! class_exists('ZipArchive')) {
    fwrite(STDERR, "php-zip missing. Enable ext-zip.\n");
    exit(1);
}

$excludePrefixes = [
    '.git/',
    '.github/',
    'deploy_script/',
    'storage/cache/',
    'storage/logs/',
    'storage/uploads/',
    'storage/backups/',
];
$excludeExact = [
    '.env',
    '.env.example',
    'OLD_Backend.zip',
    'deploy_script/backend.zip',
];
$excludeSuffixes = ['.md', '.zip', '.log'];

@unlink($outZip);
$zip = new ZipArchive;
if ($zip->open($outZip, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot create $outZip\n");
    exit(1);
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);
$count = 0;

foreach ($files as $path) {
    $rel = str_replace('\\', '/', substr($path->getPathname(), strlen($appRoot) + 1));
    if ($rel === '' || $path->isDir()) {
        continue;
    }

    foreach ($excludePrefixes as $prefix) {
        if (str_starts_with($rel, $prefix)) {
            continue 2;
        }
    }
    if (in_array($rel, $excludeExact, true)) {
        continue;
    }
    foreach ($excludeSuffixes as $suffix) {
        if (str_ends_with($rel, $suffix)) {
            continue 2;
        }
    }

    $zip->addFile($path->getPathname(), $rel);
    $count++;
}

$zip->addFile($prodEnv, '.env');
$count++;

// Keep the one-time admin seeder available on the server, but never ship
// deploy.php or the build script itself.
$seeder = $appRoot.'/deploy_script/seed-admin.php';
if (is_file($seeder)) {
    $zip->addFile($seeder, 'deploy_script/seed-admin.php');
    $count++;
}

$zip->close();

printf("backend.zip built: %d files, %.2f MB\n", $count, filesize($outZip) / 1048576);
