<?php

namespace App\Http\Requests;

use App\Enums\WeekType;
use App\Models\AcademicSeason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWeekRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageCalendar', AcademicSeason::class);
    }

    public function rules(): array
    {
        return ['week_type' => ['sometimes', Rule::enum(WeekType::class)]];
    }
}
