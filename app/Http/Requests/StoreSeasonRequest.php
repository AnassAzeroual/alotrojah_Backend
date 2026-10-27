<?php

namespace App\Http\Requests;

use App\Models\AcademicSeason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSeasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', AcademicSeason::class);
    }

    protected function prepareForValidation(): void
    {
        // Supervisors never pick a center — their seasons belong to their own.
        if ($this->user()?->role !== 'admin' && empty($this->input('center_id'))) {
            $this->merge(['center_id' => $this->user()->center_id]);
        }
    }

    public function rules(): array
    {
        return [
            // Name uniqueness is checked in the controller so the 422 carries
            // the stable SEASON_EXISTS code (FormRequest bags have none).
            'name' => ['required', 'string', 'max:50'],
            // New seasons are always center-owned (legacy NULL rows stay shared).
            'center_id' => [
                Rule::requiredIf(fn () => $this->user()?->role === 'admin'),
                'nullable', 'integer', 'exists:centers,id',
            ],
            'start_date' => ['required', 'date'],
            'hijri_year' => ['nullable', 'string', 'max:20'],
            'review_weeks_per_term' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'terms' => ['sometimes', 'array', 'min:1', 'max:12'],
            'terms.*.name' => ['required_with:terms', 'string', 'max:50'],
            'terms.*.weeks' => ['required_with:terms', 'integer', 'min:1', 'max:12'],
        ];
    }
}
