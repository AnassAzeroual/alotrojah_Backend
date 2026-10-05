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

    protected function prepareForValidation(): void
    {
        // Non-admin creators never see the center field — default to their own
        // center BEFORE validation, or the required rule below 422s a payload
        // the UI could never complete (§2.8 follow-up).
        if ($this->user()?->role !== 'admin' && empty($this->input('center_id'))) {
            $this->merge(['center_id' => $this->user()->center_id]);
        }
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'role' => ['required', Rule::enum(Role::class)],
            'phone' => ['nullable', 'string', 'max:30'],
            // §2.8: every role except admin belongs to exactly one center — a
            // center-less supervisor/teacher/board/student account is inert.
            'center_id' => [
                Rule::requiredIf(
                    fn () => in_array($this->input('role'), ['supervisor', 'teacher', 'board', 'student'], true)
                ),
                'nullable', 'integer', 'exists:centers,id',
            ],
            'teacher_type' => ['sometimes', Rule::enum(TeacherType::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
