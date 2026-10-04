<?php

namespace App\Http\Requests;

use App\Enums\ExamType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('exam'));
    }

    public function rules(): array
    {
        return [
            'exam_type' => ['sometimes', Rule::enum(ExamType::class)],
            'term_id' => ['sometimes', 'nullable', 'integer', 'exists:terms,id'],
            'exam_date' => ['sometimes', 'nullable', 'date'],
            'examiner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'examiner_report' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
