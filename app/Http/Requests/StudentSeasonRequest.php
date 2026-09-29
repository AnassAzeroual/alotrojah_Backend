<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StudentSeasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // row-level student check happens in the controller
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'season_id' => ['required', 'integer', 'exists:academic_seasons,id'],
        ];
    }
}
