<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Display-ready calendar row: the session plus the names its card title
 * needs (group/term/week), so one windowed feed replaces four lookups.
 */
class SessionCalendarResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'season_id' => $this->season_id,
            'term_id' => $this->term_id,
            'week_id' => $this->week_id,
            'group_id' => $this->group_id,
            'group_name' => $this->group?->name,
            'term_name' => $this->term?->name_ar,
            'week_number_global' => $this->week?->week_number_global,
            'week_type' => $this->week?->week_type,
            'session_number_global' => $this->session_number_global,
            'session_type' => $this->session_type,
            'planned_date' => $this->planned_date?->toDateString(),
            'start_time' => $this->start_time ? substr((string) $this->start_time, 0, 5) : null,
            'end_time' => $this->end_time ? substr((string) $this->end_time, 0, 5) : null,
            'status' => $this->status,
        ];
    }
}
