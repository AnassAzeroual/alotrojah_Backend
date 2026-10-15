<?php

namespace App\Http\Requests;

use App\Enums\Gender;
use App\Enums\MemorizationMode;
use App\Enums\StudentStatus;
use App\Enums\StudentType;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Student::class);
    }

    public function rules(): array
    {
        return [
            // Every pupil rides a login account (owner-directed 2026-10-15):
            // NULL user_id rows are rejected — pick an unlinked student user.
            'user_id' => ['required', 'integer', 'exists:users,id',
                function ($attribute, $value, $fail) {
                    $u = User::find($value);
                    if (! $u || $u->role !== 'student') {
                        $fail('The pupil account must belong to a student user.');
                    } elseif (Student::where('user_id', $value)->exists()) {
                        $fail('The pupil account is already linked.');
                    }
                }],
            'full_name' => ['required', 'string', 'max:150'],
            'center_id' => ['sometimes', 'nullable', 'integer', 'exists:centers,id'],
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'level_id' => ['required', 'integer', 'exists:levels,id'],
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
