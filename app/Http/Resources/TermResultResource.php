<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TermResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $num = fn ($v) => $v !== null ? (float) $v : null;

        return [
            'id' => $this->id, 'student_id' => $this->student_id, 'season_id' => $this->season_id,
            'term_id' => $this->term_id,
            'hifz_total' => $num($this->hifz_total), 'murajaa_total' => $num($this->murajaa_total),
            'exam_score' => $num($this->exam_score), 'general_avg' => $num($this->general_avg),
            'teacher_notes' => $this->teacher_notes, 'guardian_notes' => $this->guardian_notes,
            'supervisor_note' => $this->supervisor_note, 'honor_flag' => $this->honor_flag,
        ];
    }
}
