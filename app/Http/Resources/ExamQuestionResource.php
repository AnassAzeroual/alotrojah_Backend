<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'exam_id' => $this->exam_id, 'question_no' => $this->question_no,
            'prompt_text' => $this->prompt_text,
            'hizb_ref' => $this->hizb_ref !== null ? (float) $this->hizb_ref : null,
            'surah_ref' => $this->surah_ref, 'ayah_from' => $this->ayah_from, 'ayah_to' => $this->ayah_to,
            'sort_order' => $this->sort_order, 'model_type' => $this->model_type,
            'max_score' => $this->max_score !== null ? (float) $this->max_score : null,
            'score' => $this->score !== null ? (float) $this->score : null,
            'notes' => $this->notes,
        ];
    }
}
