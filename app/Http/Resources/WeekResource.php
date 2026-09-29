<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WeekResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'season_id' => $this->season_id, 'term_id' => $this->term_id,
            'week_number_global' => $this->week_number_global,
            'week_number_in_term' => $this->week_number_in_term,
            'week_type' => $this->week_type,
            'start_date' => $this->start_date?->toDateString(), 'end_date' => $this->end_date?->toDateString(),
            'sessions' => SessionResource::collection($this->whenLoaded('sessions')),
        ];
    }
}
