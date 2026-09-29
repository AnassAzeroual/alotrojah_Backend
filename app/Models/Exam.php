<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{    protected $table = 'exams';
    const UPDATED_AT = null;
    protected $fillable = ['student_id','season_id','term_id','exam_type','exam_date','examiner_id','overall_avg','examiner_report'];
    protected $casts = [
        'exam_date' => 'date',
        'overall_avg' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function examiner(): BelongsTo { return $this->belongsTo(User::class, 'examiner_id'); }

    public function student(): BelongsTo { return $this->belongsTo(Student::class, 'student_id'); }

    public function examQuestions(): HasMany { return $this->hasMany(ExamQuestion::class, 'exam_id'); }

    /** Alias used across controllers/resources. */
    public function questions(): HasMany { return $this->hasMany(ExamQuestion::class, 'exam_id'); }
}
