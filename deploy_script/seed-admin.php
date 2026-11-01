<?php

declare(strict_types=1);

/**
 * Create the first admin for the native v2 backend.
 *
 * Usage:
 *   php deploy_script/seed-admin.php
 *   php deploy_script/seed-admin.php --email=admin@example.org --password=password123 --name=Admin
 *
 * Defaults:
 *   ADMIN_EMAIL    admin@example.org
 *   ADMIN_PASSWORD password123
 *   ADMIN_NAME     Admin
 *
 * Safety:
 *   - Refuses to run when users already exist unless --force is passed.
 *   - Refuses to overwrite an existing email.
 *   - Never prints the password or hash.
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
use App\Core\Security;

$options = getopt('', ['email::', 'password::', 'name::', 'force']);

$email = strtolower(trim((string) ($options['email'] ?? getenv('ADMIN_EMAIL') ?: 'admin@example.org')));
$password = (string) ($options['password'] ?? getenv('ADMIN_PASSWORD') ?: 'password123');
$name = trim((string) ($options['name'] ?? getenv('ADMIN_NAME') ?: 'Admin'));
$force = array_key_exists('force', $options);

Config::load(BASE_PATH.'/.env');

if (Config::get('app_env') === 'production' && $password === 'password123' && ! $force) {
    fwrite(STDERR, "Refusing the default password in production; set ADMIN_PASSWORD or pass --force.\n");
    exit(1);
}

if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid admin email.\n");
    exit(1);
}
if (strlen($password) < 6) {
    fwrite(STDERR, "Admin password must be at least 6 characters.\n");
    exit(1);
}

$existing = Database::fetchOne(
    'SELECT id, role FROM `users` WHERE email = :email LIMIT 1',
    ['email' => $email],
);

if ($existing !== null) {
    if ($existing['role'] === 'admin') {
        echo "Admin already exists (id={$existing['id']}); nothing changed.\n";
        exit(0);
    }
    fwrite(STDERR, "Email already belongs to role {$existing['role']}; nothing changed.\n");
    exit(1);
}

$users = (int) Database::fetchValue('SELECT COUNT(*) FROM `users`');
if ($users > 0 && ! $force) {
    fwrite(STDERR, "users table is not empty ({$users} rows); refusing to create a first admin. Use --force only if intentional.\n");
    exit(1);
}

$hash = Security::hashPassword($password);
$id = Database::insert('users', [
    'full_name' => $name,
    'email' => $email,
    'password_hash' => $hash,
    'role' => 'admin',
    'is_active' => 1,
]);

echo "Created admin id={$id}, email={$email}. Password was hashed with bcrypt cost 12.\n";
