<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WeeklyGoalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'week_id' => $this->week_id,
            'target_text' => $this->target_text,
            'is_completed' => $this->is_completed === null ? null : (bool) $this->is_completed,
        ];
    }
}
