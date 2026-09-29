<?php

namespace App\Http\Requests;

use App\Enums\ExamType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', \App\Models\Exam::class);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'exam_type' => ['required', Rule::enum(ExamType::class)],
            'term_id' => ['required_if:exam_type,term_batch,hizb_completion', 'nullable', 'integer', 'exists:terms,id'],
            'season_id' => ['required_if:exam_type,final_season', 'nullable', 'integer', 'exists:academic_seasons,id'],
            'exam_date' => ['nullable', 'date'],
            'examiner_id' => ['nullable', 'integer', 'exists:users,id'],
            'examiner_report' => ['nullable', 'string'],
        ];
    }
}
