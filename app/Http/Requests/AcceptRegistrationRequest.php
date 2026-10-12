<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('accept', $this->route('registrationRequest'));
    }

    public function rules(): array
    {
        return [
            'center_id' => ['required', 'integer', 'exists:centers,id'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'level_id' => ['nullable', 'integer', 'exists:levels,id'],
        ];
    }
}
