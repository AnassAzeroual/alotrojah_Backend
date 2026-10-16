<?php

namespace App\Http\Requests;

use App\Models\Level;
use Illuminate\Foundation\Http\FormRequest;

class ResetLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Level::class);
    }

    public function rules(): array
    {
        return [
            'center_id' => ['required', 'integer', 'exists:centers,id'],
        ];
    }
}
