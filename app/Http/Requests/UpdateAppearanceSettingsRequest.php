<?php

namespace App\Http\Requests;

use App\Models\AppearanceSetting;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAppearanceSettingsRequest extends FormRequest
{
    /** #RRGGBB only: the value is emitted straight into CSS. */
    private const HEX = '/^#[0-9a-fA-F]{6}$/';

    private const SIZE = '/^(cover|contain|fill|tile|auto|(\d{1,4}(?:\.\d+)?(?:px|%))(?:\s+(?:auto|\d{1,4}(?:\.\d+)?(?:px|%)))?)$/';

    private const POSITION = '/^(center|top|bottom|left|right|top-left|top-right|bottom-left|bottom-right|(?:left|center|right|\d{1,4}(?:\.\d+)?(?:px|%))\s+(?:top|center|bottom|\d{1,4}(?:\.\d+)?(?:px|%)))$/';

    public function authorize(): bool
    {
        return $this->user()->can('manage', AppearanceSetting::class);
    }

    public function rules(): array
    {
        return [
            'center_id' => ['sometimes', 'integer', 'exists:centers,id'],
            'background_type' => ['sometimes', 'string', 'in:none,color,image,gradient'],
            'background_color' => ['sometimes', 'string', 'regex:'.self::HEX],
            'gradient_type' => ['sometimes', 'string', 'in:linear,radial,conic'],
            'gradient_angle' => ['sometimes', 'integer', 'between:0,360'],
            'gradient_from' => ['sometimes', 'string', 'regex:'.self::HEX],
            'gradient_to' => ['sometimes', 'string', 'regex:'.self::HEX],
            'background_opacity' => ['sometimes', 'integer', 'between:0,100'],
            'background_size' => ['sometimes', 'string', 'max:64', 'regex:'.self::SIZE],
            'background_position' => ['sometimes', 'string', 'max:64', 'regex:'.self::POSITION],
            'background_repeat' => ['sometimes', 'string', 'in:no-repeat,repeat,repeat-x,repeat-y,space,round'],
            'background_attachment' => ['sometimes', 'string', 'in:scroll,fixed'],
            'background_blend_mode' => [
                'sometimes',
                'string',
                'in:normal,multiply,screen,overlay,darken,lighten,color-dodge,color-burn,hard-light,soft-light,difference,exclusion,hue,saturation,color,luminosity',
            ],
            'pattern_enabled' => ['sometimes', 'boolean'],
            'pattern_opacity' => ['sometimes', 'integer', 'between:0,100'],
            'pattern_size' => ['sometimes', 'integer', 'between:32,800'],
            'pattern_tint' => ['sometimes', 'nullable', 'string', 'regex:'.self::HEX],
            'pattern_invert' => ['sometimes', 'boolean'],
            'backdrop_blur' => ['sometimes', 'integer', 'between:0,40'],
            'glass_tint' => ['sometimes', 'string', 'regex:'.self::HEX],
            'glass_opacity' => ['sometimes', 'integer', 'between:0,100'],
            'orb_enabled' => ['sometimes', 'boolean'],
            'orb_primary' => ['sometimes', 'string', 'regex:'.self::HEX],
            'orb_secondary' => ['sometimes', 'string', 'regex:'.self::HEX],
            'orb_opacity' => ['sometimes', 'integer', 'between:0,100'],
            'scrim_enabled' => ['sometimes', 'boolean'],
            'scrim_color' => ['sometimes', 'nullable', 'string', 'regex:'.self::HEX],
            'scrim_opacity' => ['sometimes', 'integer', 'between:0,100'],
        ];
    }
}
