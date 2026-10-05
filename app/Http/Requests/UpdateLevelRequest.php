<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', \App\Models\Level::class);
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'string', 'max:100'],
            'center_id' => ['sometimes', 'nullable', 'integer', 'exists:centers,id'],
            'sessions_per_week' => ['sometimes', 'integer', 'min:1', 'max:7'],
            'thumn_per_session_label' => ['sometimes', 'string', 'max:50'],
            'thumn_per_session_value' => ['sometimes', 'numeric', 'min:0'],
            'thumn_per_week_value' => ['sometimes', 'numeric', 'min:0'],
            'ahzab_per_term' => ['sometimes', 'numeric', 'min:0'],
            'ahzab_per_dawra' => ['sometimes', 'numeric', 'min:0'],
            'duration_label' => ['sometimes', 'string', 'max:100'],
            'total_ahzab' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ];
    }
}
