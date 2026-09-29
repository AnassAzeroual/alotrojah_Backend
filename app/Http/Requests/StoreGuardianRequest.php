<?php

namespace App\Http\Requests;

use App\Enums\GuardianRelation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Guardian::class);
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'phone' => ['nullable', 'string', 'max:30'],
            'relation' => ['required', Rule::enum(GuardianRelation::class)],
        ];
    }
}
