<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MurajaaReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'student_id' => $this->student_id,
            'season_id' => $this->season_id, 'term_id' => $this->term_id,
            'week_from' => $this->week_from, 'week_to' => $this->week_to,
            'weeks_covered' => $this->weeks_covered,
            'hizb_from' => $this->hizb_from !== null ? (float) $this->hizb_from : null,
            'hizb_to' => $this->hizb_to !== null ? (float) $this->hizb_to : null,
            'score' => (float) $this->score,
            'reviewed_at' => $this->reviewed_at?->toDateString(),
        ];
    }
}
