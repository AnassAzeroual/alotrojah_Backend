<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TermResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'season_id' => $this->season_id, 'term_number' => $this->term_number,
            'name_ar' => $this->name_ar,
            'start_week' => $this->start_week, 'end_week' => $this->end_week,
            'start_session_no' => $this->start_session_no, 'end_session_no' => $this->end_session_no,
            'weeks' => WeekResource::collection($this->whenLoaded('weeks')),
            'weeks_count' => $this->whenCounted('weeks'),
        ];
    }
}
