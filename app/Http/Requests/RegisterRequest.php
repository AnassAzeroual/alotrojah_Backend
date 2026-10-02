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
            'role' => ['required', Rule::in(['supervisor', 'teacher', 'student', 'board'])],
            'teacher_type' => ['nullable', 'required_if:role,teacher', Rule::enum(TeacherType::class)],
            'phone' => ['required', 'string', 'max:30'],
            'birth_date' => ['nullable', 'required_if:role,student', 'date', 'before:today'],
            'gender' => ['nullable', 'required_if:role,student', Rule::in(['male', 'female'])],
        ];
    }
}
