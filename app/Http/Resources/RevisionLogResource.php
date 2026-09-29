<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevisionLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'student_id' => $this->student_id, 'session_id' => $this->session_id,
            'hizb_from' => $this->hizb_from !== null ? (float) $this->hizb_from : null,
            'hizb_to' => $this->hizb_to !== null ? (float) $this->hizb_to : null,
            'murajaa_score' => $this->murajaa_score !== null ? (float) $this->murajaa_score : null,
        ];
    }
}
