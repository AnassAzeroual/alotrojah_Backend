<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamQuestion extends Model
{
    protected $table = 'exam_questions';
    public $timestamps = false;
    protected $fillable = ['exam_id','question_no','prompt_text','hizb_ref','surah_ref','ayah_from','ayah_to','sort_order','model_type','max_score','score','notes'];
    protected $casts = [
        'hizb_ref' => 'decimal:2',
        'max_score' => 'decimal:2',
        'score' => 'decimal:2',
    ];

    public function exam(): BelongsTo { return $this->belongsTo(Exam::class, 'exam_id'); }
}
