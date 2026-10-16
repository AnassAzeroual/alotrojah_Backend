<?php

namespace App\Http\Requests;

use App\Models\Exam;
use Illuminate\Foundation\Http\FormRequest;

class WeeklyBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Exam::class);
    }

    public function rules(): array
    {
        return [
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'exam_date' => ['required', 'date'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'rows.*.portion' => ['required', 'array'],
            'rows.*.portion.hizb' => ['nullable', 'numeric', 'between:1,60'],
            'rows.*.portion.thumn' => ['nullable', 'integer', 'between:1,8'],
            'rows.*.portion.surah' => ['nullable', 'integer', 'min:1'],
            'rows.*.portion.ayah_from' => ['nullable', 'integer', 'min:1'],
            'rows.*.portion.ayah_to' => ['nullable', 'integer', 'min:1'],
            'rows.*.scores' => ['required', 'array'],
            'rows.*.scores.*' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
