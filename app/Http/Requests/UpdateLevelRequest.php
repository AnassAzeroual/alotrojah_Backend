<?php

namespace App\Http\Requests;

use App\Models\Level;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Level::class);
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'string', 'max:100'],
            'sessions_per_week' => ['sometimes', 'integer', 'min:1', 'max:7'],
            'thumn_per_session_label' => ['sometimes', 'string', 'max:50'],
            'thumn_per_session_value' => ['sometimes', 'numeric', 'min:1', 'max:480'],
            'thumn_per_week_value' => ['sometimes', 'numeric', 'min:0'],
            'ahzab_per_term' => ['sometimes', 'numeric', 'min:0'],
            'ahzab_per_dawra' => ['sometimes', 'numeric', 'min:0'],
            'duration_label' => ['sometimes', 'string', 'max:100'],
            'total_ahzab' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ];
    }
}
