<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private string $method;
    private string $path;
    private array $headers = [];
    private array $queryParams = [];
    private array $bodyParams = [];
    private array $routeParams = [];
    private array $files = [];
    private ?array $user = null;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $rawPath = parse_url($uri, PHP_URL_PATH) ?? '/';
        $path = '/' . trim($rawPath, '/');
        if (str_starts_with($path, '/api/')) {
            $path = substr($path, 4);
        }
        $this->path = $path;

        // Parse headers
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $this->headers[$headerName] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'AUTHORIZATION'], true)) {
                $headerName = strtolower(str_replace('_', '-', $key));
                $this->headers[$headerName] = (string) $value;
            }
        }

        // Support Apache Authorization header quirks
        if (!isset($this->headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $this->headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        // X-Auth-Token fallback
        if (!isset($this->headers['authorization']) && isset($this->headers['x-auth-token'])) {
            $this->headers['authorization'] = 'Bearer ' . $this->headers['x-auth-token'];
        }

        $this->queryParams = $_GET;
        $this->files = $_FILES;

        // Parse body
        $contentType = $this->header('content-type', '');
        if (str_contains($contentType, 'application/json')) {
            $rawInput = file_get_contents('php://input');
            if (!empty($rawInput)) {
                $decoded = json_decode($rawInput, true);
                if (is_array($decoded)) {
                    $this->bodyParams = $decoded;
                }
            }
        } else {
            $this->bodyParams = $_POST;
            // For PUT/PATCH/DELETE with form-urlencoded
            if (empty($this->bodyParams) && in_array($this->method, ['PUT', 'PATCH', 'DELETE'], true)) {
                $rawInput = file_get_contents('php://input');
                if (!empty($rawInput)) {
                    parse_str($rawInput, $parsed);
                    if (is_array($parsed)) {
                        $this->bodyParams = $parsed;
                    }
                }
            }
        }
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function getBearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth && preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
            return trim($matches[1]);
        }
        $custom = $this->header('x-auth-token');
        if ($custom) {
            return trim($custom);
        }
        return null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->routeParams)) {
            return $this->routeParams[$key];
        }
        if (array_key_exists($key, $this->bodyParams)) {
            return $this->bodyParams[$key];
        }
        if (array_key_exists($key, $this->queryParams)) {
            return $this->queryParams[$key];
        }
        return $default;
    }

    public function all(): array
    {
        return array_merge($this->queryParams, $this->bodyParams, $this->routeParams);
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->queryParams;
        }
        return $this->queryParams[$key] ?? $default;
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->bodyParams;
        }
        return $this->bodyParams[$key] ?? $default;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function filled(string $key): bool
    {
        $val = $this->input($key);
        return $val !== null && $val !== '';
    }

    public function file(string $name): ?array
    {
        return $this->files[$name] ?? null;
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function routeParam(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function setUser(?array $user): void
    {
        $this->user = $user;
    }

    public function getUser(): ?array
    {
        return $this->user;
    }

    public function user(): ?array
    {
        return $this->user;
    }

    public function userId(): ?int
    {
        return isset($this->user['id']) ? (int) $this->user['id'] : null;
    }

    public function role(): ?string
    {
        return $this->user['role'] ?? null;
    }

    public function centerId(): ?int
    {
        return isset($this->user['center_id']) && $this->user['center_id'] !== null
            ? (int) $this->user['center_id']
            : null;
    }

    public function teacherType(): ?string
    {
        return $this->user['teacher_type'] ?? null;
    }

    public function ip(): string
    {
        return $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '127.0.0.1';
    }
}
