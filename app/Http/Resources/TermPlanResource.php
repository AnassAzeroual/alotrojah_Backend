<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TermPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'student_id' => $this->student_id, 'season_id' => $this->season_id,
            'term_id' => $this->term_id, 'goal_text' => $this->goal_text, 'plan_mode' => $this->plan_mode,
            'start_hizb' => $this->start_hizb !== null ? (float) $this->start_hizb : null,
            'end_hizb' => $this->end_hizb !== null ? (float) $this->end_hizb : null,
            'plan_surah_from' => $this->plan_surah_from, 'plan_ayah_from' => $this->plan_ayah_from,
            'plan_surah_to' => $this->plan_surah_to, 'plan_ayah_to' => $this->plan_ayah_to,
            'expected_hifz_week_thumn' => $this->expected_hifz_week_thumn !== null ? (float) $this->expected_hifz_week_thumn : null,
            'expected_hifz_term_ahzab' => $this->expected_hifz_term_ahzab !== null ? (float) $this->expected_hifz_term_ahzab : null,
            'expected_hifz_season_ahzab' => $this->expected_hifz_season_ahzab !== null ? (float) $this->expected_hifz_season_ahzab : null,
            'khatm_expected_at' => $this->khatm_expected_at,
        ];
    }
}
