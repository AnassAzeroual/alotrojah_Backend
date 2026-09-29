<?php

namespace App\Http\Requests;

use App\Enums\HonorFlag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertSeasonResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', \App\Models\SeasonResult::class);
    }

    public function rules(): array
    {
        $score = ['nullable', 'numeric', 'min:0', 'max:20'];

        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'season_id' => ['required', 'integer', 'exists:academic_seasons,id'],
            'total_memorized_label' => ['nullable', 'string', 'max:200'],
            'total_memorized_thumn' => ['nullable', 'numeric', 'min:0'],
            'hifz_total' => $score, 'murajaa_total' => $score, 'overall_avg' => $score,
            'board_report' => ['nullable', 'string'],
            'honor_flag' => ['sometimes', Rule::enum(HonorFlag::class)],
        ];
    }
}
