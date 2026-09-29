<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyGoal extends Model
{
    protected $table = 'weekly_goals';
    public $timestamps = false;
    protected $fillable = ['student_id','season_id','week_id','target_text','is_completed','checked_by','checked_at'];
    protected $casts = [
        'checked_at' => 'datetime',
        'is_completed' => 'boolean',
    ];

    public function student(): BelongsTo { return $this->belongsTo(Student::class, 'student_id'); }

    public function week(): BelongsTo { return $this->belongsTo(Week::class, 'week_id'); }
}
