<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermResult extends Model
{
    protected $table = 'term_results';
    public $timestamps = false;
    protected $fillable = ['student_id','season_id','term_id','hifz_total','murajaa_total','exam_score','general_avg','teacher_notes','supervisor_note','honor_flag'];
    protected $casts = [
        'hifz_total' => 'decimal:2',
        'murajaa_total' => 'decimal:2',
        'exam_score' => 'decimal:2',
        'general_avg' => 'decimal:2',
    ];

    public function student(): BelongsTo { return $this->belongsTo(Student::class, 'student_id'); }
}
