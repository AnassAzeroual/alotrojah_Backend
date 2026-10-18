<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property 'none'|'tashji3'|'intibah' $honor_flag
 */
class TermResult extends Model
{
    protected $table = 'term_results';
    public $timestamps = false;
    protected $fillable = ['student_id','season_id','term_id','hifz_total','murajaa_total','exam_score','general_avg','teacher_notes','supervisor_note','honor_flag'];
    protected $casts = [
        'hifz_total' => 'float',
        'murajaa_total' => 'float',
        'exam_score' => 'float',
        'general_avg' => 'float',
    ];

    public function student(): BelongsTo { return $this->belongsTo(Student::class, 'student_id'); }
}
