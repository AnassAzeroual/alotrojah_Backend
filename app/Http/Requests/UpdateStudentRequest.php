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

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('student'));
    }

    public function rules(): array
    {
        return [
            // Linking an account later (grandfathered NULL rows); explicit
            // null is refused by the non-nullable rules — unlinking is not a thing.
            'user_id' => ['sometimes', 'integer', 'exists:users,id',
                function ($attribute, $value, $fail) {
                    $u = User::find($value);
                    if (! $u || $u->role !== 'student') {
                        $fail('The pupil account must belong to a student user.');
                    } elseif (Student::where('user_id', $value)
                        ->where('id', '!=', $this->route('student')->id)->exists()) {
                        $fail('The pupil account is already linked.');
                    }
                }],
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
