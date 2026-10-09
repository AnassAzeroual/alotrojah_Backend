<?php

namespace App\Http\Requests;

use App\Enums\Gender;
use App\Enums\MemorizationMode;
use App\Enums\StudentStatus;
use App\Enums\StudentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('student'));
    }

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:150'],
            'center_id' => ['sometimes', 'nullable', 'integer', 'exists:centers,id'],
            'group_id' => ['sometimes', 'integer', 'exists:groups,id'],
            'level_id' => ['sometimes', 'integer', 'exists:levels,id'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', Rule::enum(Gender::class)],
            'status' => ['sometimes', Rule::enum(StudentStatus::class)],
            'student_type' => ['sometimes', 'nullable', Rule::enum(StudentType::class)],
            'memorization_mode' => ['sometimes', Rule::enum(MemorizationMode::class)],
            'start_hizb' => ['sometimes', 'nullable', 'numeric', 'between:1,60'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
