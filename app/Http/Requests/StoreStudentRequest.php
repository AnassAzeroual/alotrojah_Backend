<?php

namespace App\Http\Requests;

use App\Enums\Gender;
use App\Enums\MemorizationMode;
use App\Enums\StudentStatus;
use App\Enums\StudentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Student::class);
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'center_id' => ['sometimes', 'nullable', 'integer', 'exists:centers,id'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'level_id' => ['nullable', 'integer', 'exists:levels,id'],
            'guardian_id' => ['nullable', 'integer', 'exists:guardians,id'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'enrollment_date' => ['nullable', 'date'],
            'status' => [Rule::enum(StudentStatus::class)],
            'student_type' => ['nullable', Rule::enum(StudentType::class)],
            'memorization_mode' => ['required', Rule::enum(MemorizationMode::class)],
            'start_hizb' => ['nullable', 'numeric', 'between:1,60'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
