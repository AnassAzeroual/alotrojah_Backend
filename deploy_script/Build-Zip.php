<?php
declare(strict_types=1);
// Builds backend.zip from the Laravel app root with production excludes.
// Usage: php deploy_script/Build-Zip.php [path-to-prod-env]
$appRoot = dirname(__DIR__);
$outZip = $appRoot . '/deploy_script/backend.zip';
$prodEnv = $argv[1] ?? ($appRoot . '/deploy_script/.env.production');
if (!file_exists($prodEnv)) { fwrite(STDERR, "Missing prod env: $prodEnv (copy .env.production.example)\n"); exit(1); }
if (!class_exists('ZipArchive')) { fwrite(STDERR, "php-zip missing. Enable ext-zip.\n"); exit(1); }
$excludePrefixes = ['.git/', '.github/', 'tests/', 'docs/', 'node_modules/', 'deploy_script/', 'storage/logs/', 'storage/framework/cache/', 'storage/framework/sessions/', 'storage/framework/views/'];
$excludeExact = ['.env', '.env.example', '.editorconfig', 'phpunit.xml', '.phpunit.result.cache', 'backend.zip', 'deploy_script/backend.zip'];
$excludeSuffix = ['/tests/', '/Tests/'];
$excludeExt = ['.md'];
@unlink($outZip);
$zip = new ZipArchive();
if ($zip->open($outZip, ZipArchive::CREATE) !== true) { fwrite(STDERR, "Cannot create $outZip\n"); exit(1); }
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
$n = 0;
foreach ($files as $path) {
    $rel = str_replace('\\', '/', substr($path->getPathname(), strlen($appRoot) + 1));
    if ($rel === '' || str_starts_with($rel, 'deploy_script/backend.zip')) continue;
    foreach ($excludePrefixes as $p) { if (str_starts_with($rel, $p)) continue 2; }
    if (in_array($rel, $excludeExact, true) || in_array(basename($rel), $excludeExact, true)) continue;
    if ($path->isDir()) continue;
    foreach ($excludeSuffix as $s) { if (str_contains('/' . $rel, $s)) continue 2; }
    foreach ($excludeExt as $e) { if (str_ends_with($rel, $e) && str_starts_with($rel, 'vendor/')) continue 2; }
    if (preg_match('#^database/.*\.sqlite$#', $rel)) continue;
    if (basename($rel) === '.sqlite') continue;
    $zip->addFile($path->getPathname(), $rel);
    $n++;
}
$zip->addFile($prodEnv, '.env');
$n++;
$zip->close();
printf("backend.zip built: %d files, %.2f MB\n", $n, filesize($outZip) / 1048576);
