<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Student extends Model
{
    protected $table = 'students';
    const UPDATED_AT = null;
    protected $fillable = ['user_id','guardian_id','group_id','center_id','level_id','full_name','birth_date','gender','enrollment_date','status','student_type','memorization_mode','start_hizb','notes'];
    protected $casts = [
        'birth_date' => 'date',
        'enrollment_date' => 'date',
        'start_hizb' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function scopeForCenter(Builder $q, int $centerId): void { $q->where($this->getTable().'.center_id', $centerId); }

    public function group(): BelongsTo { return $this->belongsTo(Group::class, 'group_id'); }

    public function center(): BelongsTo { return $this->belongsTo(Center::class, 'center_id'); }

    public function guardian(): BelongsTo { return $this->belongsTo(Guardian::class, 'guardian_id'); }

    public function level(): BelongsTo { return $this->belongsTo(Level::class, 'level_id'); }

    public function attendances(): HasMany { return $this->hasMany(Attendance::class, 'student_id'); }

    public function exams(): HasMany { return $this->hasMany(Exam::class, 'student_id'); }

    public function memorizationLogs(): HasMany { return $this->hasMany(MemorizationLog::class, 'student_id'); }

    public function murajaaReviews(): HasMany { return $this->hasMany(MurajaaReview::class, 'student_id'); }

    public function revisionLogs(): HasMany { return $this->hasMany(RevisionLog::class, 'student_id'); }

    public function seasonResults(): HasMany { return $this->hasMany(SeasonResult::class, 'student_id'); }

    public function sessionScores(): HasMany { return $this->hasMany(SessionScore::class, 'student_id'); }

    public function termPlans(): HasMany { return $this->hasMany(TermPlan::class, 'student_id'); }

    public function termResults(): HasMany { return $this->hasMany(TermResult::class, 'student_id'); }

    public function weeklyGoals(): HasMany { return $this->hasMany(WeeklyGoal::class, 'student_id'); }
}
