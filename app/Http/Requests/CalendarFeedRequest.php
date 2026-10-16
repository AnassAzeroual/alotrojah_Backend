<?php

namespace App\Http\Requests;

use App\Models\AcademicSeason;
use Illuminate\Foundation\Http\FormRequest;

class CalendarFeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewCalendar', AcademicSeason::class);
    }

    public function rules(): array
    {
        return [
            'season_id' => ['required', 'integer', 'exists:academic_seasons,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ];
    }
}
