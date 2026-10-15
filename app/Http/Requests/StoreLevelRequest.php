<?php

namespace App\Http\Requests;

use App\Models\Level;
use Illuminate\Foundation\Http\FormRequest;

class StoreLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Level::class);
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:100'],
            'center_id' => ['sometimes', 'nullable', 'integer', 'exists:centers,id'],
            'sessions_per_week' => ['required', 'integer', 'min:1', 'max:7'],
            'thumn_per_session_label' => ['required', 'string', 'max:50'],
            'thumn_per_session_value' => ['required', 'numeric', 'min:1', 'max:480'],
            'thumn_per_week_value' => ['required', 'numeric', 'min:0'],
            'ahzab_per_term' => ['required', 'numeric', 'min:0'],
            'ahzab_per_dawra' => ['required', 'numeric', 'min:0'],
            'duration_label' => ['required', 'string', 'max:100'],
            'total_ahzab' => ['required', 'integer', 'min:1', 'max:60'],
        ];
    }
}
