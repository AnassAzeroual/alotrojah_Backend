<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSeasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', \App\Models\AcademicSeason::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', 'unique:academic_seasons,name'],
            'start_date' => ['required', 'date'],
            'hijri_year' => ['nullable', 'string', 'max:20'],
            'sessions_per_week' => ['sometimes', 'integer', 'min:1', 'max:7'],
            'review_weeks_per_term' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'terms' => ['sometimes', 'array', 'min:1', 'max:12'],
            'terms.*.name' => ['required_with:terms', 'string', 'max:50'],
            'terms.*.weeks' => ['required_with:terms', 'integer', 'min:1', 'max:12'],
        ];
    }
}
