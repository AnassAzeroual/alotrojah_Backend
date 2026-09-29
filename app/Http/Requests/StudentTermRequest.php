<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StudentTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // row-level student check happens in the controller
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'term_id' => ['required', 'integer', 'exists:terms,id'],
        ];
    }
}
