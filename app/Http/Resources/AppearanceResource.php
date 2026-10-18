<?php

namespace App\Http\Resources;

use App\Models\AppearanceSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The payload every /appearance endpoint answers with: the center's stored row
 * with the server defaults filling each unset field, plus absolute file URLs.
 *
 * Living in a resource gives the OpenAPI spec one named schema for all four
 * endpoints instead of four inline copies, and the `@return` shape below is
 * what the frontend generates its type from — keep it in step with the array
 * and with the `in:` rules in UpdateAppearanceSettingsRequest.
 *
 * @mixin AppearanceSetting
 */
class AppearanceResource extends JsonResource
{
    public function __construct(
        private readonly ?int $centerId,
        private readonly ?AppearanceSetting $setting,
    ) {
        parent::__construct($setting);
    }

    /**
     * Absolute URLs are built from the request host: APP_URL is fixed to :8000,
     * but the verify/shots harness serves the same backend on :8002.
     *
     * @return array{
     *     center_id: int|null,
     *     background_url: string|null,
     *     background_type: 'none'|'color'|'image'|'gradient',
     *     background_color: string,
     *     gradient_type: 'linear'|'radial'|'conic',
     *     gradient_angle: int,
     *     gradient_from: string,
     *     gradient_to: string,
     *     background_opacity: int,
     *     background_size: string,
     *     background_position: string,
     *     background_repeat: 'no-repeat'|'repeat'|'repeat-x'|'repeat-y'|'space'|'round',
     *     background_attachment: 'scroll'|'fixed',
     *     background_blend_mode: 'normal'|'multiply'|'screen'|'overlay'|'darken'|'lighten'|'color-dodge'|'color-burn'|'hard-light'|'soft-light'|'difference'|'exclusion'|'hue'|'saturation'|'color'|'luminosity',
     *     pattern_url: string|null,
     *     pattern_enabled: bool,
     *     pattern_opacity: int,
     *     pattern_size: int,
     *     pattern_tint: string|null,
     *     pattern_invert: bool,
     *     backdrop_blur: int,
     *     glass_tint: string,
     *     glass_opacity: int,
     *     orb_enabled: bool,
     *     orb_primary: string,
     *     orb_secondary: string,
     *     orb_opacity: int,
     *     scrim_enabled: bool,
     *     scrim_color: string|null,
     *     scrim_opacity: int,
     *     updated_at: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        $setting = $this->setting;
        $centerId = $this->centerId;
        $d = AppearanceSetting::DEFAULTS;

        return [
            'center_id' => $centerId === null ? null : (int) $centerId,
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
