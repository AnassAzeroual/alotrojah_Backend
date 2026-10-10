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
            // Full 24h day (views render 0→23): clocks cap at 23:59 — no rollover.
            'start_time' => ['sometimes', 'date_format:H:i', 'before_or_equal:23:59'],
            'end_time' => ['sometimes', 'date_format:H:i', 'before_or_equal:23:59'],
            'status' => ['sometimes', Rule::enum(SessionStatus::class)],
            'session_type' => ['sometimes', Rule::enum(SessionType::class)],
        ];
    }
}
