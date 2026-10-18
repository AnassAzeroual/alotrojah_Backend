<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\UpdateAppearanceSettingsRequest;
use App\Http\Requests\UploadAppearanceRequest;
use App\Http\Resources\AppearanceResource;
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
 *
 * All four endpoints answer with the same {@see AppearanceResource} payload.
 */
class AppearanceController extends Controller
{
    /**
     * Read the appearance payload for a center (defaults fill unset fields).
     *
     * Everyone reads their own center; admins may pass center_id to read any
     * center (for the global admin, center_id is required — they have none).
     *
     * @response array{success: bool, message: string|null, data: AppearanceResource}
     */
    public function show(Request $request): JsonResponse
    {
        $centerId = $this->centerId($request);
        $setting = $centerId !== null ? AppearanceSetting::forCenter($centerId) : null;

        return $this->ok(new AppearanceResource($centerId, $setting));
    }

    /**
     * Partial update of the display controls (opacity, fit, position, size).
     *
     * @response array{success: bool, message: string|null, data: AppearanceResource}
     */
    public function update(UpdateAppearanceSettingsRequest $request): JsonResponse
    {
        $centerId = $this->centerId($request, true);
        $setting = AppearanceSetting::forCenterOrCreate($centerId);
        $setting->fill($request->safe()->except(['center_id']));
        $setting->updated_by = $request->user()->id;
        $setting->save();

        return $this->ok(new AppearanceResource($centerId, $setting->fresh()));
    }

    /**
     * Upload a background/pattern image (multipart, field `image`).
     *
     * The file lands on the public disk (no storage symlink on the production
     * host); the previous file of the same kind is deleted. Uploading a
     * background switches background_type to image automatically.
     *
     * @response array{success: bool, message: string|null, data: AppearanceResource}
     */
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

        return $this->ok(new AppearanceResource($centerId, $setting->fresh()));
    }

    /**
     * Remove the background/pattern image (manage permission).
     *
     * Removing the active background switches background_type to none. The
     * file is deleted from the disk.
     *
     * @response array{success: bool, message: string|null, data: AppearanceResource}
     */
    public function destroy(Request $request, string $kind): JsonResponse
    {
        $this->authorize('manage', AppearanceSetting::class);

        $centerId = $this->centerId($request, true);
        $setting = AppearanceSetting::forCenter($centerId);
        if ($setting === null) {
            return $this->ok(new AppearanceResource($centerId, null));
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

        return $this->ok(new AppearanceResource($centerId, $setting->fresh()));
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
}
