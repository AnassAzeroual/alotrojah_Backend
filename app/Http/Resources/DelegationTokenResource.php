<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DelegationTokenResource extends JsonResource
{
    /** Token string itself is returned only once, at generation (see controller). */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'group_id' => $this->group_id,
            'duration_minutes' => $this->duration_minutes,
            'expires_at' => $this->expires_at,
            'used_by_teacher_id' => $this->used_by_teacher_id,
            'is_revoked' => (bool) $this->is_revoked,
        ];
    }
}
