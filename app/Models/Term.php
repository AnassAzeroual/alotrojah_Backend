<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Term extends Model
{
    protected $table = 'terms';
    public $timestamps = false;
    protected $fillable = ['season_id','term_number','name_ar','start_week','end_week','start_session_no','end_session_no'];

    public function season(): BelongsTo { return $this->belongsTo(AcademicSeason::class, 'season_id'); }

    public function sessions(): HasMany { return $this->hasMany(Session::class, 'term_id'); }

    public function termPlans(): HasMany { return $this->hasMany(TermPlan::class, 'term_id'); }

    public function weeks(): HasMany { return $this->hasMany(Week::class, 'term_id'); }
}
