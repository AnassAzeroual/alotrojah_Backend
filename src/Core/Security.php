<?php

declare(strict_types=1);

namespace App\Core;

final class Security
{
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function checkRateLimit(string $actionKey, int $maxRequests = 60, int $decaySeconds = 60): bool
    {
        $dir = (string) Config::get('storage_path') . '/cache/rate_limit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $file = $dir . '/' . md5($actionKey) . '.json';
        $now = microtime(true);
        $windowStart = $now - $decaySeconds;

        $requests = [];
        if (file_exists($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                // Filter out timestamps older than decaySeconds
                $requests = array_filter($data, fn($ts) => $ts > $windowStart);
            }
        }

        if (count($requests) >= $maxRequests) {
            return false;
        }

        $requests[] = $now;
        file_put_contents($file, json_encode(array_values($requests)), LOCK_EX);
        return true;
    }

    public static function sanitizeString(?string $str): string
    {
        if ($str === null) {
            return '';
        }
        return trim(htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
