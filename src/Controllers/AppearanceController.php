<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class AppearanceController extends BaseController
{
    private function formatAppearance(?array $row, ?int $centerId, Request $request): array
    {
        $appUrl = rtrim((string) Config::get('app_url', 'http://localhost:8000'), '/');
        // Prefer request host if available
        $host = $request->header('host');
        if ($host) {
            $proto = $request->header('x-forwarded-proto', 'http');
            $baseUrl = "{$proto}://{$host}";
        } else {
            $baseUrl = $appUrl;
        }

        $bgUrl = null;
        if (!empty($row['background_path'])) {
            $bgFilename = basename($row['background_path']);
            $bgUrl = "{$baseUrl}/api/v1/appearance/files/background/{$bgFilename}";
        }

        $patternUrl = null;
        if (!empty($row['pattern_path'])) {
            $patFilename = basename($row['pattern_path']);
            $patternUrl = "{$baseUrl}/api/v1/appearance/files/pattern/{$patFilename}";
        }

        return [
            'center_id' => $centerId,
            'background_url' => $bgUrl,
            'background_type' => $row['background_type'] ?? 'none',
            'background_color' => $row['background_color'] ?? '#ffffff',
            'gradient_type' => $row['gradient_type'] ?? 'linear',
            'gradient_angle' => isset($row['gradient_angle']) ? (int) $row['gradient_angle'] : 135,
            'gradient_from' => $row['gradient_from'] ?? '#667eea',
            'gradient_to' => $row['gradient_to'] ?? '#764ba2',
            'background_opacity' => isset($row['background_opacity']) ? (int) $row['background_opacity'] : 100,
            'background_size' => $row['background_size'] ?? 'cover',
            'background_position' => $row['background_position'] ?? 'center',
            'background_repeat' => $row['background_repeat'] ?? 'no-repeat',
            'background_attachment' => $row['background_attachment'] ?? 'scroll',
            'background_blend_mode' => $row['background_blend_mode'] ?? 'normal',
            'pattern_url' => $patternUrl,
            'pattern_enabled' => (bool) ($row['pattern_enabled'] ?? false),
            'pattern_opacity' => isset($row['pattern_opacity']) ? (int) $row['pattern_opacity'] : 20,
            'pattern_size' => isset($row['pattern_size']) ? (int) $row['pattern_size'] : 32,
            'pattern_tint' => $row['pattern_tint'] ?? null,
            'pattern_invert' => (bool) ($row['pattern_invert'] ?? false),
            'backdrop_blur' => isset($row['backdrop_blur']) ? (int) $row['backdrop_blur'] : 0,
            'glass_tint' => $row['glass_tint'] ?? '#ffffff',
            'glass_opacity' => isset($row['glass_opacity']) ? (int) $row['glass_opacity'] : 80,
            'orb_enabled' => (bool) ($row['orb_enabled'] ?? false),
            'orb_primary' => $row['orb_primary'] ?? '#6366f1',
            'orb_secondary' => $row['orb_secondary'] ?? '#ec4899',
            'orb_opacity' => isset($row['orb_opacity']) ? (int) $row['orb_opacity'] : 30,
            'scrim_enabled' => (bool) ($row['scrim_enabled'] ?? false),
            'scrim_color' => $row['scrim_color'] ?? '#000000',
            'scrim_opacity' => isset($row['scrim_opacity']) ? (int) $row['scrim_opacity'] : 20,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    public function show(Request $request): Response
    {
        $centerId = $this->centerId($request, false);
        $setting = null;
        if ($centerId !== null) {
            $setting = Database::fetchOne("SELECT * FROM `appearance_settings` WHERE center_id = :cid LIMIT 1", [
                'cid' => $centerId,
            ]);
        }

        return $this->ok($this->formatAppearance($setting, $centerId, $request));
    }

    public function update(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);
        $centerId = $this->centerId($request, true);

        $allowedFields = [
            'background_type', 'background_color', 'gradient_type', 'gradient_angle',
            'gradient_from', 'gradient_to', 'background_opacity', 'background_size',
            'background_position', 'background_repeat', 'background_attachment',
            'background_blend_mode', 'pattern_enabled', 'pattern_opacity', 'pattern_size',
            'pattern_tint', 'pattern_invert', 'backdrop_blur', 'glass_tint',
            'glass_opacity', 'orb_enabled', 'orb_primary', 'orb_secondary', 'orb_opacity',
            'scrim_enabled', 'scrim_color', 'scrim_opacity',
        ];

        $updateData = [];
        foreach ($allowedFields as $f) {
            $val = $request->input($f);
            if ($val !== null) {
                if (in_array($f, ['pattern_enabled', 'pattern_invert', 'orb_enabled', 'scrim_enabled'], true)) {
                    $updateData[$f] = (int) filter_var($val, FILTER_VALIDATE_BOOLEAN);
                } else {
                    $updateData[$f] = $val;
                }
            }
        }

        $updateData['updated_by'] = $this->userId($request);

        $existing = Database::fetchOne("SELECT id FROM `appearance_settings` WHERE center_id = :cid", ['cid' => $centerId]);
        if ($existing) {
            Database::update('appearance_settings', $updateData, 'id = :id', ['id' => $existing['id']]);
        } else {
            $updateData['center_id'] = $centerId;
            Database::insert('appearance_settings', $updateData);
        }

        $fresh = Database::fetchOne("SELECT * FROM `appearance_settings` WHERE center_id = :cid LIMIT 1", ['cid' => $centerId]);
        return $this->ok($this->formatAppearance($fresh, $centerId, $request));
    }

    public function upload(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);
        $kind = $params['kind'] ?? '';
        if (!in_array($kind, ['background', 'pattern'], true)) {
            return $this->fail('Invalid kind.', 404);
        }

        $file = $request->file('image');
        if (!$file || empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return $this->fail('Image file is required.', 422, ['image' => ['The image field is required.']]);
        }

        $centerId = $this->centerId($request, true);
        $uploadDir = (string) Config::get('upload_path') . "/appearance/{$kind}";
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return $this->fail('Failed to upload file.', 500);
        }

        $relativeCol = "{$kind}_path";
        $relativePath = "appearance/{$kind}/{$filename}";

        $existing = Database::fetchOne("SELECT id, {$relativeCol} FROM `appearance_settings` WHERE center_id = :cid", ['cid' => $centerId]);
        $fill = [
            $relativeCol => $relativePath,
            'updated_by' => $this->userId($request),
        ];

        if ($kind === 'background') {
            $fill['background_type'] = 'image';
        }

        if ($existing) {
            Database::update('appearance_settings', $fill, 'id = :id', ['id' => $existing['id']]);
            if (!empty($existing[$relativeCol])) {
                $oldFile = (string) Config::get('upload_path') . '/' . $existing[$relativeCol];
                if (file_exists($oldFile)) {
                    @unlink($oldFile);
                }
            }
        } else {
            $fill['center_id'] = $centerId;
            Database::insert('appearance_settings', $fill);
        }

        $fresh = Database::fetchOne("SELECT * FROM `appearance_settings` WHERE center_id = :cid LIMIT 1", ['cid' => $centerId]);
        return $this->ok($this->formatAppearance($fresh, $centerId, $request));
    }

    public function destroy(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);
        $kind = $params['kind'] ?? '';
        if (!in_array($kind, ['background', 'pattern'], true)) {
            return $this->fail('Invalid kind.', 404);
        }

        $centerId = $this->centerId($request, true);
        $relativeCol = "{$kind}_path";
        $existing = Database::fetchOne("SELECT id, {$relativeCol}, background_type FROM `appearance_settings` WHERE center_id = :cid", ['cid' => $centerId]);

        if ($existing) {
            $fill = [
                $relativeCol => null,
                'updated_by' => $this->userId($request),
            ];
            if ($kind === 'background' && ($existing['background_type'] ?? '') === 'image') {
                $fill['background_type'] = 'none';
            }

            Database::update('appearance_settings', $fill, 'id = :id', ['id' => $existing['id']]);

            if (!empty($existing[$relativeCol])) {
                $oldFile = (string) Config::get('upload_path') . '/' . $existing[$relativeCol];
                if (file_exists($oldFile)) {
                    @unlink($oldFile);
                }
            }
        }

        $fresh = Database::fetchOne("SELECT * FROM `appearance_settings` WHERE center_id = :cid LIMIT 1", ['cid' => $centerId]);
        return $this->ok($this->formatAppearance($fresh, $centerId, $request));
    }

    public function file(Request $request, array $params): Response
    {
        $kind = $params['kind'] ?? '';
        $filename = $params['filename'] ?? '';

        if (!in_array($kind, ['background', 'pattern'], true) ||
            $filename === '' ||
            $filename !== basename($filename) ||
            str_contains($filename, '..')) {
            return $this->fail('File not found', 404);
        }

        $filePath = (string) Config::get('upload_path') . "/appearance/{$kind}/{$filename}";
        return Response::file($filePath, $filename);
    }
}
