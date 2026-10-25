<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property 'model1'|'model2_full'|'single'|null $model_type
 * @property int|null $thumn_ref
 */
class ExamQuestion extends Model
{
    protected $table = 'exam_questions';

    public $timestamps = false;

    protected $fillable = ['exam_id', 'question_no', 'prompt_text', 'hizb_ref', 'thumn_ref', 'surah_ref', 'ayah_from', 'ayah_to', 'sort_order', 'model_type', 'max_score', 'score', 'notes'];

    protected $casts = [
        'hizb_ref' => 'float',
        'thumn_ref' => 'integer',
        'max_score' => 'float',
        'score' => 'float',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }
}
