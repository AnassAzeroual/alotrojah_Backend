<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $group = $this->route('group');

        return $group && $this->user()->can('generate', $group);
    }

    public function rules(): array
    {
        return ['minutes' => ['required', 'integer', 'in:15,30,60,120']];
    }
}
