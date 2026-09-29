<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'role' => $this->role,
            'phone' => $this->phone,
            'center_id' => $this->center_id,
            'teacher_type' => $this->teacher_type,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
