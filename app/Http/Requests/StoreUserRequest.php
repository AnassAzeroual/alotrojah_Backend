<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Enums\TeacherType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\User::class);
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'role' => ['required', Rule::enum(Role::class)],
            'phone' => ['nullable', 'string', 'max:30'],
            'center_id' => ['nullable', 'integer', 'exists:centers,id'],
            'teacher_type' => ['sometimes', Rule::enum(TeacherType::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
