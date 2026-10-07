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
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i'],
            'status' => ['sometimes', Rule::enum(SessionStatus::class)],
            'session_type' => ['sometimes', Rule::enum(SessionType::class)],
        ];
    }
}
