<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReplaceUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('user'));
    }

    public function rules(): array
    {
        return [
            'replacer_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
