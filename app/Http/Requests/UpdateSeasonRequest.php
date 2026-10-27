<?php

namespace App\Http\Requests;

use App\Models\AcademicSeason;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSeasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', AcademicSeason::class);
    }

    public function rules(): array
    {
        return [
            // Name uniqueness is checked in the controller so the 422 carries
            // the stable SEASON_EXISTS code (FormRequest bags have none).
            'name' => ['sometimes', 'string', 'max:50'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'hijri_year' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }
}
