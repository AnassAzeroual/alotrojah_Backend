<?php

namespace App\Http\Requests;

use App\Enums\HonorFlag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertTermResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', \App\Models\TermResult::class);
    }

    public function rules(): array
    {
        $score = ['nullable', 'numeric', 'min:0', 'max:20'];

        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'term_id' => ['required', 'integer', 'exists:terms,id'],
            'hifz_total' => $score, 'murajaa_total' => $score,
            'exam_score' => $score, 'general_avg' => $score,
            'teacher_notes' => ['nullable', 'string'],
            'guardian_notes' => ['nullable', 'string'],
            'supervisor_note' => ['nullable', 'string'],
            'honor_flag' => ['sometimes', Rule::enum(HonorFlag::class)],
        ];
    }
}
