<?php

namespace App\Http\Requests;

use App\Enums\MemorizationMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertTermPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', \App\Models\TermPlan::class);
    }

    public function rules(): array
    {
        // cross-field range checks (hizb order, ayah bounds) live in the controller
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'term_id' => ['required', 'integer', 'exists:terms,id'],
            'goal_text' => ['nullable', 'string'],
            'plan_mode' => ['required', Rule::enum(MemorizationMode::class)],
            'start_hizb' => ['nullable', 'numeric', 'between:1,60'],
            'end_hizb' => ['nullable', 'numeric', 'between:1,60'],
            'plan_surah_from' => ['nullable', 'integer', 'exists:surahs,id'],
            'plan_ayah_from' => ['nullable', 'integer', 'min:1'],
            'plan_surah_to' => ['nullable', 'integer', 'exists:surahs,id'],
            'plan_ayah_to' => ['nullable', 'integer', 'min:1'],
            'expected_hifz_week_thumn' => ['nullable', 'numeric', 'min:0'],
            'expected_hifz_term_ahzab' => ['nullable', 'numeric', 'min:0'],
            'expected_hifz_season_ahzab' => ['nullable', 'numeric', 'min:0'],
            'khatm_expected_at' => ['nullable', 'string', 'max:100'],
        ];
    }
}
