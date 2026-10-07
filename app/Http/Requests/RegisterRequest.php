<?php

namespace App\Http\Requests;

use App\Enums\TeacherType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:3', 'max:150'],
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            // §2.8 follow-up: the waiting room can only ever mint teacher/student
            // accounts (accept() rejects anything else), so the public form
            // must not promise supervisor/board roles that can never be approved.
            'role' => ['required', Rule::in(['teacher', 'student'])],
            'teacher_type' => ['nullable', 'required_if:role,teacher', Rule::enum(TeacherType::class)],
            'phone' => ['required', 'string', 'max:30'],
            'birth_date' => ['nullable', 'required_if:role,student', 'date', 'before:today'],
            'gender' => ['nullable', 'required_if:role,student', Rule::in(['male', 'female'])],
        ];
    }
}
