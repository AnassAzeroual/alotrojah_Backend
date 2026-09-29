<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('exam'));
    }

    public function rules(): array
    {
        return [
            'exam_date' => ['sometimes', 'nullable', 'date'],
            'examiner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'examiner_report' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
