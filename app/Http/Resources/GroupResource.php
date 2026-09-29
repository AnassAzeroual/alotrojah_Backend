<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'center_id' => $this->center_id,
            'level_id' => $this->level_id,
            'academic_year' => $this->academic_year,
            'capacity' => $this->capacity,
            'schedule_days' => $this->schedule_days,
            'is_active' => (bool) $this->is_active,
            'teacher' => $this->whenLoaded('teacher', fn () => [
                'id' => $this->teacher->id, 'full_name' => $this->teacher->full_name,
            ]),
            'students_count' => $this->whenCounted('students'),
        ];
    }
}
