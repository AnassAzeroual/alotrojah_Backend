<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $config = [];
    private static bool $loaded = false;

    public static function load(string $basePath): void
    {
        if (self::$loaded) {
            return;
        }

        self::$config = [
            'app_name' => 'AlOtrojah API (Native PHP 8.5)',
            'app_env' => 'production',
            'app_debug' => false,
            'app_url' => 'http://localhost:8000',
            'app_timezone' => 'Africa/Casablanca',

            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => 'alotrojah_dev',
            'db_username' => 'alotrojah',
            'db_password' => '',

            'jwt_secret' => '',
            'jwt_algo' => 'HS256',
            'jwt_ttl' => 60, // in minutes
            'jwt_refresh_ttl' => 20160, // in minutes

            'frontend_urls' => [
                'http://localhost:4200',
                'http://127.0.0.1:4201',
                'http://localhost:4201',
                'http://localhost:4210',
                'http://127.0.0.1:4210',
                'https://alotrojah.ma',
                'https://www.alotrojah.ma',
            ],
        ];

        $envFile = is_file($basePath) ? $basePath : rtrim($basePath, '/\\') . '/.env';
        $rootPath = is_file($basePath) ? dirname($basePath) : rtrim($basePath, '/\\');

        self::$config['base_path'] = $rootPath;
        self::$config['storage_path'] = $rootPath . '/storage';
        self::$config['upload_path'] = $rootPath . '/storage/uploads';

        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }
                $key = strtolower(trim(substr($line, 0, $pos)));
                $val = trim(substr($line, $pos + 1));

                // Handle quotes
                if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                    (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                    $val = substr($val, 1, -1);
                }

                if ($val === 'true' || $val === '(true)') {
                    $val = true;
                } elseif ($val === 'false' || $val === '(false)') {
                    $val = false;
                } elseif ($val === 'null' || $val === '(null)') {
                    $val = null;
                } elseif (is_numeric($val) && !str_starts_with($val, '0')) {
                    $val = str_contains($val, '.') ? (float) $val : (int) $val;
                }

                self::$config[$key] = $val;
            }
        }

        // Overlay with putenv / $_ENV if present
        foreach (self::$config as $key => $val) {
            $upperKey = strtoupper($key);
            if (isset($_ENV[$upperKey]) && $_ENV[$upperKey] !== '') {
                self::$config[$key] = $_ENV[$upperKey];
            } elseif (($getenvVal = getenv($upperKey)) !== false && $getenvVal !== '') {
                self::$config[$key] = $getenvVal;
            }
        }

        if (is_string(self::$config['frontend_urls'])) {
            self::$config['frontend_urls'] = array_map('trim', explode(',', self::$config['frontend_urls']));
        }

        date_default_timezone_set(self::$config['app_timezone'] ?? 'Africa/Casablanca');
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$config[strtolower($key)] ?? $default;
    }

    public static function all(): array
    {
        return self::$config;
    }
}
