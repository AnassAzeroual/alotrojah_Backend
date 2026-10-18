<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One background + pattern config and its display controls per center.
 *
 * The CSS-value columns are annotated with their literal unions so Scramble
 * emits them precisely instead of widening to `string`; keep them in step with
 * the `in:` rules in UpdateAppearanceSettingsRequest.
 *
 * @property 'none'|'color'|'image'|'gradient' $background_type
 * @property string $background_color
 * @property 'linear'|'radial'|'conic' $gradient_type
 * @property string $gradient_from
 * @property string $gradient_to
 * @property string $background_size
 * @property string $background_position
 * @property 'no-repeat'|'repeat'|'repeat-x'|'repeat-y'|'space'|'round' $background_repeat
 * @property 'scroll'|'fixed' $background_attachment
 * @property 'normal'|'multiply'|'screen'|'overlay'|'darken'|'lighten'|'color-dodge'|'color-burn'|'hard-light'|'soft-light'|'difference'|'exclusion'|'hue'|'saturation'|'color'|'luminosity' $background_blend_mode
 * @property string|null $pattern_tint
 * @property string $glass_tint
 * @property string $orb_primary
 * @property string $orb_secondary
 * @property string|null $scrim_color
 */
class AppearanceSetting extends Model
{
    protected $table = 'appearance_settings';

    protected $primaryKey = 'center_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'center_id',
        'background_path',
        'background_type',
        'background_color',
        'gradient_type',
        'gradient_angle',
        'gradient_from',
        'gradient_to',
        'background_opacity',
        'background_size',
        'background_position',
        'background_repeat',
        'background_attachment',
        'background_blend_mode',
        'pattern_path',
        'pattern_enabled',
        'pattern_opacity',
        'pattern_size',
        'pattern_tint',
        'pattern_invert',
        'backdrop_blur',
        'glass_tint',
        'glass_opacity',
        'orb_enabled',
        'orb_primary',
        'orb_secondary',
        'orb_opacity',
        'scrim_enabled',
        'scrim_color',
        'scrim_opacity',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'background_opacity' => 'integer',
            'gradient_angle' => 'integer',
            'pattern_enabled' => 'boolean',
            'pattern_opacity' => 'integer',
            'pattern_size' => 'integer',
            'pattern_invert' => 'boolean',
            'backdrop_blur' => 'integer',
            'glass_opacity' => 'integer',
            'orb_enabled' => 'boolean',
            'orb_opacity' => 'integer',
            'scrim_enabled' => 'boolean',
            'scrim_opacity' => 'integer',
        ];
    }

    /** Server-side defaults, shared by new rows and the no-row payload. */
    public const DEFAULTS = [
        'background_type' => 'none',
        'background_color' => '#0e9f6e',
        'gradient_type' => 'linear',
        'gradient_angle' => 135,
        'gradient_from' => '#0e9f6e',
        'gradient_to' => '#0b3b2f',
        'background_opacity' => 100,
        'background_size' => 'cover',
        'background_position' => 'center',
        'background_repeat' => 'no-repeat',
        'background_attachment' => 'scroll',
        'background_blend_mode' => 'normal',
        'pattern_enabled' => true,
        'pattern_opacity' => 16,
        'pattern_size' => 120,
        'pattern_tint' => null,
        'pattern_invert' => false,
        'backdrop_blur' => 0,
        'glass_tint' => '#ffffff',
        'glass_opacity' => 0,
        'orb_enabled' => true,
        'orb_primary' => '#00b8a9',
        'orb_secondary' => '#8b5cf6',
        'orb_opacity' => 50,
        'scrim_enabled' => true,
        'scrim_color' => null,
        'scrim_opacity' => 72,
    ];

    public static function forCenter(int $centerId): ?self
    {
        return static::query()->find($centerId);
    }

    /** First write for a center materializes a row seeded with the defaults. */
    public static function forCenterOrCreate(int $centerId): self
    {
        return static::query()->firstOrCreate(
            ['center_id' => $centerId],
            self::DEFAULTS,
        );
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class, 'center_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
