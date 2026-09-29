<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicSeason extends Model
{
    protected $table = 'academic_seasons';
    public $timestamps = false;
    protected $fillable = ['name','hijri_year','start_date','end_date','total_weeks','total_sessions','is_current'];
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
    ];

    public function seasonResults(): HasMany { return $this->hasMany(SeasonResult::class, 'season_id'); }

    public function sessions(): HasMany { return $this->hasMany(Session::class, 'season_id'); }

    public function terms(): HasMany { return $this->hasMany(Term::class, 'season_id'); }

    public function termPlans(): HasMany { return $this->hasMany(TermPlan::class, 'season_id'); }

    public function weeks(): HasMany { return $this->hasMany(Week::class, 'season_id'); }
}
