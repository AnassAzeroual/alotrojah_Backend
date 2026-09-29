<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeasonResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $num = fn ($v) => $v !== null ? (float) $v : null;

        return [
            'id' => $this->id, 'student_id' => $this->student_id, 'season_id' => $this->season_id,
            'total_memorized_label' => $this->total_memorized_label,
            'total_memorized_thumn' => $num($this->total_memorized_thumn),
            'hifz_total' => $num($this->hifz_total), 'murajaa_total' => $num($this->murajaa_total),
            'overall_avg' => $num($this->overall_avg),
            'board_report' => $this->board_report, 'honor_flag' => $this->honor_flag,
        ];
    }
}
