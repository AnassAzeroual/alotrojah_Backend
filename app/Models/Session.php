<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Session extends Model
{
    protected $table = 'sessions';

    public $timestamps = false;

    protected $fillable = ['season_id', 'term_id', 'week_id', 'group_id', 'session_number_global', 'session_number_in_week', 'session_type', 'planned_date', 'start_time', 'end_time', 'status'];

    protected $casts = [
        'planned_date' => 'date',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(AcademicSeason::class, 'season_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'term_id');
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class, 'week_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'session_id');
    }

    public function memorizationLogs(): HasMany
    {
        return $this->hasMany(MemorizationLog::class, 'session_id');
    }

    public function murajaaReviews(): HasMany
    {
        return $this->hasMany(MurajaaReview::class, 'session_id');
    }

    public function revisionLogs(): HasMany
    {
        return $this->hasMany(RevisionLog::class, 'session_id');
    }

    public function sessionScores(): HasMany
    {
        return $this->hasMany(SessionScore::class, 'session_id');
    }
}
