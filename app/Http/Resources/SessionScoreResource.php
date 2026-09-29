<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionScoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'session_id' => $this->session_id,
            'module' => $this->whenLoaded('module', fn () => [
                'code' => $this->module->code, 'name_ar' => $this->module->name_ar,
                'max_points' => (float) $this->module->max_points,
            ]),
            'score' => (float) $this->score,
        ];
    }
}
