<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScoringModule extends Model
{
    protected $table = 'scoring_modules';
    const UPDATED_AT = null;
    protected $fillable = ['code','name_ar','max_points','scope','is_active','is_in_weekly_total','sort_order','created_by'];
    protected $casts = [
        'max_points' => 'decimal:2',
        'created_at' => 'datetime',
        'is_active' => 'boolean',
        'is_in_weekly_total' => 'boolean',
    ];

    public function scopeActive(Builder $q): void { $q->where($this->getTable().'.is_active', true); }

    public function sessionScores(): HasMany { return $this->hasMany(SessionScore::class, 'module_id'); }
}
