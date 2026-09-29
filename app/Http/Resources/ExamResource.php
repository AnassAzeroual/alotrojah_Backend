<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'student_id' => $this->student_id,
            'season_id' => $this->season_id, 'term_id' => $this->term_id,
            'exam_type' => $this->exam_type, 'exam_date' => $this->exam_date?->toDateString(),
            'examiner_id' => $this->examiner_id,
            'overall_avg' => $this->overall_avg !== null ? (float) $this->overall_avg : null,
            'examiner_report' => $this->examiner_report,
            'questions' => ExamQuestionResource::collection($this->whenLoaded('questions')),
        ];
    }
}
