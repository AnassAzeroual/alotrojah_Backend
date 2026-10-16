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
            'term_name' => $this->whenLoaded('term', fn () => $this->resource->term?->name_ar, null),
            'season_name' => $this->whenLoaded('season', fn () => $this->resource->season?->name, null),
            'week_number_global' => $this->week_number_global,
            'week_number_in_term' => $this->week_number_in_term,
            'week_type' => $this->week_type,
            'start_date' => $this->start_date?->toDateString(), 'end_date' => $this->end_date?->toDateString(),
            'sessions' => SessionResource::collection($this->whenLoaded('sessions')),
        ];
    }
}
