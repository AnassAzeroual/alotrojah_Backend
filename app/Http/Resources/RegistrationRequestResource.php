<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegistrationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'role' => $this->role,
            'teacher_type' => $this->teacher_type,
            'phone' => $this->phone,
            'birth_date' => $this->birth_date?->toDateString(),
            'gender' => $this->gender,
            'requested_at' => $this->requested_at?->toIso8601String(),
        ];
    }
}
