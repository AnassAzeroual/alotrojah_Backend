<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class ScoringModule extends Model
{
    protected $table = 'scoring_modules';
    const UPDATED_AT = null;
    protected $fillable = ['code','name_ar','max_points','scope','is_active','is_in_weekly_total','sort_order','created_by','center_id'];
    protected $casts = [
        'max_points' => 'decimal:2',
        'created_at' => 'datetime',
        'is_active' => 'boolean',
        'is_in_weekly_total' => 'boolean',
    ];

    public function scopeActive(Builder $q): void { $q->where($this->getTable().'.is_active', true); }

    /**
     * Copy-on-write sets (per owner decision): the effective set for a center
     * is its override rows plus the shared defaults (center_id NULL) for codes
     * it does not override. NULL center = the default template itself.
     *
     * @return Collection<string, static> keyed by code
     */
    public static function effectiveFor(?int $centerId): Collection
    {
        $rows = static::orderBy('sort_order')->get();
        if ($centerId === null) return $rows->whereNull('center_id')->keyBy('code');
        $out = $rows->whereNull('center_id')->keyBy('code');
        foreach ($rows->where('center_id', $centerId)->keyBy('code') as $code => $row) {
            $out[$code] = $row;
        }

        return $out;
    }

    /**
     * Materialize a center's override set by cloning the current effective
     * values. No-op when overrides already exist. Returns the override rows.
     */
    public static function ensureOverrideSet(int $centerId): Collection
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

    public function sessionScores(): HasMany { return $this->hasMany(SessionScore::class, 'module_id'); }
}
