<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'season_id' => $this->season_id, 'term_id' => $this->term_id,
            'week_id' => $this->week_id,
            'session_number_global' => $this->session_number_global,
            'session_number_in_week' => $this->session_number_in_week,
            'session_type' => $this->session_type,
            'planned_date' => $this->planned_date?->toDateString(),
            'status' => $this->status,
        ];
    }
}
