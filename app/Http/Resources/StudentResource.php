<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'center_id' => $this->center_id,
            'group' => $this->whenLoaded('group', fn () => ['id' => $this->group->id, 'name' => $this->group->name]),
            'level_id' => $this->level_id,
            'birth_date' => $this->birth_date?->toDateString(),
            'enrollment_date' => $this->enrollment_date?->toDateString(),
            'notes' => $this->notes,
            'user_id' => $this->user_id,
            'gender' => $this->gender,
            'status' => $this->status,
            'student_type' => $this->student_type,
            'memorization_mode' => $this->memorization_mode,
            'start_hizb' => $this->start_hizb ? (float) $this->start_hizb : null,
        ];
    }
}
