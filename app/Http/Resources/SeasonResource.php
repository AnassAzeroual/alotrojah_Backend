<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeasonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'hijri_year' => $this->hijri_year,
            'start_date' => $this->start_date?->toDateString(), 'end_date' => $this->end_date?->toDateString(),
            'total_weeks' => $this->total_weeks, 'total_sessions' => $this->total_sessions,
            'is_current' => (bool) $this->is_current,
            'terms_count' => $this->whenCounted('terms'),
            'first_term_id' => $this->first_term_id ?? null,
        ];
    }
}
