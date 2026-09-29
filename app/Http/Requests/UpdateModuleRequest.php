<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', \App\Models\ScoringModule::class);
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'string', 'max:120'],
            'max_points' => ['sometimes', 'numeric', 'min:0', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            'is_in_weekly_total' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
