<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScoringModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'name_ar' => $this->name_ar,
            'center_id' => $this->center_id,
            'max_points' => (float) $this->max_points, 'scope' => $this->scope,
            'is_active' => (bool) $this->is_active,
            'is_in_weekly_total' => (bool) $this->is_in_weekly_total,
            'sort_order' => $this->sort_order,
        ];
    }
}
