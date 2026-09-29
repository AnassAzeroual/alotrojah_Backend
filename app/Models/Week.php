<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Week extends Model
{
    protected $table = 'weeks';
    public $timestamps = false;
    protected $fillable = ['season_id','term_id','week_number_global','week_number_in_term','week_type','start_date','end_date'];
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function season(): BelongsTo { return $this->belongsTo(AcademicSeason::class, 'season_id'); }

    public function term(): BelongsTo { return $this->belongsTo(Term::class, 'term_id'); }

    public function sessions(): HasMany { return $this->hasMany(Session::class, 'week_id'); }

    public function weeklyGoals(): HasMany { return $this->hasMany(WeeklyGoal::class, 'week_id'); }
}
