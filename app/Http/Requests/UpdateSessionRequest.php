<?php

namespace App\Http\Requests;

use App\Enums\SessionStatus;
use App\Enums\SessionType;
use App\Models\AcademicSeason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageCalendar', AcademicSeason::class);
    }

    public function rules(): array
    {
        return [
            'planned_date' => ['sometimes', 'date'],
            // Day ceiling 22:00 everywhere (views render 6→22).
            'start_time' => ['sometimes', 'date_format:H:i', 'before_or_equal:22:00'],
            'end_time' => ['sometimes', 'date_format:H:i', 'before_or_equal:22:00'],
            'status' => ['sometimes', Rule::enum(SessionStatus::class)],
            'session_type' => ['sometimes', Rule::enum(SessionType::class)],
        ];
    }
}
