<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\UpdateAppearanceSettingsRequest;
use App\Http\Requests\UploadAppearanceRequest;
use App\Models\AppearanceSetting;
use App\Models\Center;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Per-center background + pattern uploads and display controls. Every signed-in
 * user reads their own center's row; admins may target any center by passing
 * center_id. Files sit on the public disk and are streamed by {@see self::file()}
 * — the production host has no storage symlink.
 */
class AppearanceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $centerId = $this->centerId($request);
        $setting = $centerId !== null ? AppearanceSetting::forCenter($centerId) : null;

        return $this->ok($this->payload($request, $centerId, $setting));
    }

    /** Partial update of the display controls (opacity, fit, position, size). */
    public function update(UpdateAppearanceSettingsRequest $request): JsonResponse
    {
        $centerId = $this->centerId($request, true);
        $setting = AppearanceSetting::forCenterOrCreate($centerId);
        $setting->fill($request->safe()->except(['center_id']));
        $setting->updated_by = $request->user()->id;
        $setting->save();

        return $this->ok($this->payload($request, $centerId, $setting->fresh()));
    }

    public function upload(UploadAppearanceRequest $request, string $kind): JsonResponse
    {
        $centerId = $this->centerId($request, true);
        $setting = AppearanceSetting::forCenterOrCreate($centerId);
        $column = "{$kind}_path";
        $old = $setting->{$column};
        $path = $request->file('image')->store("appearance/{$kind}", 'public');

        $fill = [$column => $path, 'updated_by' => $request->user()->id];
        // Uploading a background selects the image mode.
        if ($kind === 'background') {
            $fill['background_type'] = 'image';
        }

        $setting->forceFill($fill)->save();
        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return $this->ok($this->payload($request, $centerId, $setting->fresh()));
    }

    public function destroy(Request $request, string $kind): JsonResponse
    {
        $this->authorize('manage', AppearanceSetting::class);

        $centerId = $this->centerId($request, true);
        $setting = AppearanceSetting::forCenter($centerId);
        if ($setting === null) {
            return $this->ok($this->payload($request, $centerId, null));
        }

        $column = "{$kind}_path";
        $old = $setting->{$column};

        $fill = [$column => null, 'updated_by' => $request->user()->id];
        // Removing the active image leaves no layer to show.
        if ($kind === 'background' && $setting->background_type === 'image') {
            $fill['background_type'] = 'none';
        }

        $setting->forceFill($fill)->save();
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $this->ok($this->payload($request, $centerId, $setting->fresh()));
    }

    /** Public streaming endpoint referenced by the CSS layer on every client. */
    public function file(string $kind, string $filename): StreamedResponse
    {
        // Route constraints already limit the shape; reject traversal anyway.
        if ($filename === '' || $filename !== basename($filename) || str_contains($filename, '..')) {
            abort(404);
        }

        $path = "appearance/{$kind}/{$filename}";
        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $filename, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Resolves the center a request is about. Admins choose any center via
     * center_id (the global admin has no center of their own); every other
     * role is pinned to its own center regardless of the payload.
     */
    private function centerId(Request $request, bool $required = false): ?int
    {
        $user = $request->user();
        $requested = $request->input('center_id');

        if ($user->role === 'admin' && $requested !== null && $requested !== '') {
            $centerId = filter_var($requested, FILTER_VALIDATE_INT);
            if ($centerId === false || $centerId < 1 || ! Center::whereKey($centerId)->exists()) {
                throw ValidationException::withMessages([
                    'center_id' => ['The selected center is invalid.'],
                ]);
            }

            return (int) $centerId;
        }

        $centerId = $user->center_id !== null ? (int) $user->center_id : null;

        if ($required && $centerId === null) {
            throw ValidationException::withMessages([
                'center_id' => ['Select a center first.'],
            ]);
        }

        return $centerId;
    }

    /**
     * Absolute URLs built from the request host: APP_URL is fixed to :8000,
     * but the verify/shots harness serves the same backend on :8002.
     */
    private function payload(Request $request, ?int $centerId, ?AppearanceSetting $setting): array
    {
        $d = AppearanceSetting::DEFAULTS;

        return [
            'center_id' => $centerId,
            'background_url' => $this->fileUrl($request, 'background', $setting?->background_path),
            'background_type' => $setting?->background_type ?? $d['background_type'],
            'background_color' => $setting?->background_color ?? $d['background_color'],
            'gradient_type' => $setting?->gradient_type ?? $d['gradient_type'],
            'gradient_angle' => (int) ($setting?->gradient_angle ?? $d['gradient_angle']),
            'gradient_from' => $setting?->gradient_from ?? $d['gradient_from'],
            'gradient_to' => $setting?->gradient_to ?? $d['gradient_to'],
            'background_opacity' => (int) ($setting?->background_opacity ?? $d['background_opacity']),
            'background_size' => $setting?->background_size ?? $d['background_size'],
            'background_position' => $setting?->background_position ?? $d['background_position'],
            'background_repeat' => $setting?->background_repeat ?? $d['background_repeat'],
            'background_attachment' => $setting?->background_attachment ?? $d['background_attachment'],
            'background_blend_mode' => $setting?->background_blend_mode ?? $d['background_blend_mode'],
            'pattern_url' => $this->fileUrl($request, 'pattern', $setting?->pattern_path),
            'pattern_enabled' => (bool) ($setting?->pattern_enabled ?? $d['pattern_enabled']),
            'pattern_opacity' => (int) ($setting?->pattern_opacity ?? $d['pattern_opacity']),
            'pattern_size' => (int) ($setting?->pattern_size ?? $d['pattern_size']),
            'pattern_tint' => $setting?->pattern_tint,
            'pattern_invert' => (bool) ($setting?->pattern_invert ?? $d['pattern_invert']),
            'backdrop_blur' => (int) ($setting?->backdrop_blur ?? $d['backdrop_blur']),
            'glass_tint' => $setting?->glass_tint ?? $d['glass_tint'],
            'glass_opacity' => (int) ($setting?->glass_opacity ?? $d['glass_opacity']),
            'orb_enabled' => (bool) ($setting?->orb_enabled ?? $d['orb_enabled']),
            'orb_primary' => $setting?->orb_primary ?? $d['orb_primary'],
            'orb_secondary' => $setting?->orb_secondary ?? $d['orb_secondary'],
            'orb_opacity' => (int) ($setting?->orb_opacity ?? $d['orb_opacity']),
            'scrim_enabled' => (bool) ($setting?->scrim_enabled ?? $d['scrim_enabled']),
            'scrim_color' => $setting?->scrim_color ?? $d['scrim_color'],
            'scrim_opacity' => (int) ($setting?->scrim_opacity ?? $d['scrim_opacity']),
            'updated_at' => $setting?->updated_at?->toIso8601String(),
        ];
    }

    private function fileUrl(Request $request, string $kind, ?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return $request->getSchemeAndHttpHost()
            .'/api/v1/appearance/files/'.$kind.'/'.rawurlencode(basename($path));
    }
}
