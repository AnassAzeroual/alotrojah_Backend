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
use Illuminate\Validation\Validator;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Student::class);
    }

    public function rules(): array
    {
        return [
            // Either link an existing student account (user_id) or create the
            // account in the same request (account). Both are validated below.
            'user_id' => ['nullable', 'integer', 'exists:users,id',
                function ($attribute, $value, $fail) {
                    if ($value === null) {
                        return;
                    }
                    $u = User::find($value);
                    if (! $u || $u->role !== 'student') {
                        $fail('The pupil account must belong to a student user.');
                    } elseif (Student::where('user_id', $value)->exists()) {
                        $fail('The pupil account is already linked.');
                    }
                }],
            'account' => ['nullable', 'array'],
            'account.email' => ['required_with:account', 'email', 'max:150', 'unique:users,email'],
            'account.password' => ['required_with:account', 'string', 'min:8', 'max:72'],
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

    /**
     * A pupil must carry exactly one account source: an existing user_id or a
     * new account object. Nested email/password rules handle partial accounts.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $account = $this->input('account');
            $hasAccount = is_array($account)
                && ($this->filled('account.email') || $this->filled('account.password'));
            $hasUser = $this->filled('user_id');

            if ($hasUser === $hasAccount) {
                $validator->errors()->add(
                    'account',
                    $hasUser
                        ? 'Choose either an existing account or a new one, not both.'
                        : 'Provide an existing pupil account or a new account (email and password).'
                );
            }
        });
    }
}
