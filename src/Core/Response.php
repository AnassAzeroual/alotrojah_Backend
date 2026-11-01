<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private mixed $content = null;
    private bool $isStream = false;
    private ?string $filePath = null;

    public function __construct(mixed $content = null, int $status = 200, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $status;
        $this->headers = $headers;
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        return new self($data, $status, $headers);
    }

    public static function ok(mixed $data = null, ?string $message = null): self
    {
        return self::json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], 200);
    }

    public static function created(mixed $data = null, ?string $message = null): self
    {
        return self::json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], 201);
    }

    public static function fail(string $message, int $statusCode = 400, array $errors = []): self
    {
        if (!isset($errors['code'])) {
            $errors['code'] = 'ERROR';
        }

        return self::json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $statusCode);
    }

    public static function file(string $filePath, string $filename, array $headers = []): self
    {
        if (!file_exists($filePath)) {
            return self::fail('File not found', 404);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath) ?: 'application/octet-stream';
        finfo_close($finfo);

        $defaultHeaders = [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) filesize($filePath),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $res = new self(null, 200, array_merge($defaultHeaders, $headers));
        $res->isStream = true;
        $res->filePath = $filePath;
        return $res;
    }

    public function header(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getContent(): mixed
    {
        if (is_array($this->content) || is_object($this->content)) {
            return json_encode($this->content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $this->content;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);

            // Security headers
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('X-XSS-Protection: 1; mode=block');

            foreach ($this->headers as $key => $value) {
                header("{$key}: {$value}");
            }
        }

        if ($this->isStream && $this->filePath !== null) {
            readfile($this->filePath);
            return;
        }

        if ($this->content !== null) {
            if (is_array($this->content) || is_object($this->content)) {
                echo json_encode($this->content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                echo (string) $this->content;
            }
        }
    }
}
