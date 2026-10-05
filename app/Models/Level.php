<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Level extends Model
{
    protected $table = 'levels';
    public $timestamps = false;
    protected $fillable = ['code','name_ar','sessions_per_week','thumn_per_session_label','thumn_per_session_value','thumn_per_week_value','ahzab_per_term','ahzab_per_dawra','duration_label','total_ahzab','center_id'];

    /**
     * Copy-on-write sets (same semantics as ScoringModule::effectiveFor):
     * overrides win, shared defaults (center_id NULL) fill the gaps.
     *
     * @return \Illuminate\Support\Collection<string, static> keyed by code
     */
    public static function effectiveFor(?int $centerId): \Illuminate\Support\Collection
    {
        $rows = static::orderBy('id')->get();
        if ($centerId === null) return $rows->whereNull('center_id')->keyBy('code');
        $out = $rows->whereNull('center_id')->keyBy('code');
        foreach ($rows->where('center_id', $centerId)->keyBy('code') as $code => $row) {
            $out[$code] = $row;
        }

        return $out;
    }

    /**
     * Materialize a center's override set by cloning the current effective
     * values. No-op when overrides already exist.
     */
    public static function ensureOverrideSet(int $centerId): \Illuminate\Support\Collection
    {
        $existing = static::where('center_id', $centerId)->get()->keyBy('code');
        if ($existing->isNotEmpty()) return $existing;
        foreach (static::effectiveFor($centerId) as $code => $row) {
            $copy = $row->replicate();
            $copy->center_id = $centerId;
            $copy->save();
            $existing[$code] = $copy->fresh();
        }

        return $existing;
    }
    protected $casts = [
        'thumn_per_session_value' => 'decimal:2',
        'thumn_per_week_value' => 'decimal:2',
        'ahzab_per_term' => 'decimal:2',
        'ahzab_per_dawra' => 'decimal:2',
    ];

    public function groups(): HasMany { return $this->hasMany(Group::class, 'level_id'); }

    public function students(): HasMany { return $this->hasMany(Student::class, 'level_id'); }
}
