<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class JWT
{
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public static function encode(array $payload, ?int $ttlMinutes = null): string
    {
        $secret = (string) Config::get('jwt_secret');
        if (empty($secret)) {
            throw new RuntimeException("JWT_SECRET is not configured");
        }

        $now = time();
        $ttl = ($ttlMinutes ?? (int) Config::get('jwt_ttl', 60)) * 60;

        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
        ];

        $payload['iat'] = $payload['iat'] ?? $now;
        $payload['nbf'] = $payload['nbf'] ?? $now;
        $payload['exp'] = $payload['exp'] ?? ($now + $ttl);
        $payload['jti'] = $payload['jti'] ?? bin2hex(random_bytes(16));

        $headerJson = json_encode($header, JSON_UNESCAPED_SLASHES);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $base64Header = self::base64UrlEncode($headerJson);
        $base64Payload = self::base64UrlEncode($payloadJson);

        $signature = hash_hmac('sha256', "{$base64Header}.{$base64Payload}", $secret, true);
        $base64Signature = self::base64UrlEncode($signature);

        return "{$base64Header}.{$base64Payload}.{$base64Signature}";
    }

    public static function decode(string $token): ?array
    {
        $secret = (string) Config::get('jwt_secret');
        if (empty($secret)) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        // Verify signature
        $expectedSig = self::base64UrlEncode(hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true));
        if (!hash_equals($expectedSig, $sigB64)) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($payloadB64);
        if ($payloadJson === false) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        // Check expiration
        $now = time();
        if (isset($payload['exp']) && $payload['exp'] < $now) {
            return null;
        }
        if (isset($payload['nbf']) && $payload['nbf'] > $now) {
            return null;
        }

        // Check blacklist
        if (isset($payload['jti']) && self::isBlacklisted($payload['jti'])) {
            return null;
        }

        return $payload;
    }

    public static function blacklist(string $token): void
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return;
        }

        $payloadJson = self::base64UrlDecode($parts[1]);
        if ($payloadJson === false) {
            return;
        }

        $payload = json_decode($payloadJson, true);
        if (is_array($payload) && isset($payload['jti'], $payload['exp'])) {
            $ttl = max(0, $payload['exp'] - time());
            if ($ttl > 0) {
                self::addBlacklist($payload['jti'], $ttl);
            }
        }
    }

    private static function getBlacklistDir(): string
    {
        $dir = (string) Config::get('storage_path') . '/cache/jwt_blacklist';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    private static function addBlacklist(string $jti, int $ttl): void
    {
        $file = self::getBlacklistDir() . '/' . md5($jti);
        file_put_contents($file, (string) (time() + $ttl), LOCK_EX);
    }

    private static function isBlacklisted(string $jti): bool
    {
        $file = self::getBlacklistDir() . '/' . md5($jti);
        if (!file_exists($file)) {
            return false;
        }

        $expiresAt = (int) file_get_contents($file);
        if (time() > $expiresAt) {
            @unlink($file);
            return false;
        }

        return true;
    }
}
