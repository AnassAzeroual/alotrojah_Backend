<?php

namespace App\Http\Requests;

use App\Enums\GuardianRelation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('guardian'));
    }

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:150'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'relation' => ['sometimes', Rule::enum(GuardianRelation::class)],
        ];
    }
}
