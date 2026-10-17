<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\UploadAppearanceRequest;
use App\Models\AppearanceSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * App-wide background + pattern uploads. Files sit on the public disk and are
 * streamed by {@see self::file()} — the production host has no storage symlink.
 */
class AppearanceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->ok($this->payload($request, AppearanceSetting::current()));
    }

    public function upload(UploadAppearanceRequest $request, string $kind): JsonResponse
    {
        $setting = AppearanceSetting::current();
        $column = "{$kind}_path";
        $old = $setting->{$column};
        $path = $request->file('image')->store("appearance/{$kind}", 'public');

        $setting->forceFill([$column => $path, 'updated_by' => $request->user()->id])->save();
        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return $this->ok($this->payload($request, $setting->fresh()));
    }

    public function destroy(Request $request, string $kind): JsonResponse
    {
        $this->authorize('manage', AppearanceSetting::class);

        $setting = AppearanceSetting::current();
        $column = "{$kind}_path";
        $old = $setting->{$column};

        $setting->forceFill([$column => null, 'updated_by' => $request->user()->id])->save();
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $this->ok($this->payload($request, $setting->fresh()));
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
     * Absolute URLs built from the request host: APP_URL is fixed to :8000,
     * but the verify/shots harness serves the same backend on :8002.
     */
    private function payload(Request $request, AppearanceSetting $setting): array
    {
        return [
            'background_url' => $this->fileUrl($request, 'background', $setting->background_path),
            'pattern_url' => $this->fileUrl($request, 'pattern', $setting->pattern_path),
            'updated_at' => $setting->updated_at?->toIso8601String(),
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
