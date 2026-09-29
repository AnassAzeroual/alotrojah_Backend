<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RedeemDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === 'teacher';
    }

    public function rules(): array
    {
        return ['token' => ['required', 'string', 'size:64']];
    }
}
