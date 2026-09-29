<?php

namespace App\Http\Requests;

use App\Enums\SessionStatus;
use App\Enums\SessionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageCalendar', \App\Models\AcademicSeason::class);
    }

    public function rules(): array
    {
        return [
            'planned_date' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::enum(SessionStatus::class)],
            'session_type' => ['sometimes', Rule::enum(SessionType::class)],
        ];
    }
}
