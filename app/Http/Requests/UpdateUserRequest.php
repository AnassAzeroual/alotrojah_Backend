<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Enums\TeacherType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:150', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:72'],
            'role' => ['sometimes', Rule::enum(Role::class)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'center_id' => ['sometimes', 'nullable', 'integer', 'exists:centers,id'],
            'teacher_type' => ['sometimes', Rule::enum(TeacherType::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
