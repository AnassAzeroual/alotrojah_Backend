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
            'guardian' => $this->whenLoaded('guardian', fn () => ['id' => $this->guardian->id, 'full_name' => $this->guardian->full_name]),
            'birth_date' => $this->birth_date?->toDateString(),
            'gender' => $this->gender,
            'status' => $this->status,
            'student_type' => $this->student_type,
            'memorization_mode' => $this->memorization_mode,
            'start_hizb' => $this->start_hizb ? (float) $this->start_hizb : null,
        ];
    }
}
